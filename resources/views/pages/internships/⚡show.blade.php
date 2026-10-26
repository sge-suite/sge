<?php

use App\Actions\ManageInternship;
use App\Actions\ManageSupervisorEvaluation;
use App\Models\SupervisorEvaluation;
use App\Actions\UpdateAdvisorGrades;
use App\Support\InternshipGradeCalculator;
use Illuminate\Validation\ValidationException;
use App\Actions\PreviewInternshipCalendar;
use App\Enums\InternshipStatus;
use App\Enums\AffiliationType;
use App\Models\Internship;
use App\Support\ActivityAccess;
use App\Support\ActivityHistory;
use App\Support\StudentInternshipContext;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Detalhes do estágio')] class extends Component
{
    use WithPagination;

    #[Locked] public int $internshipId;
    #[Locked] public int $affiliationId;
    #[Locked] public ?int $pauseId = null;
    #[Locked] public ?int $pauseToRemove = null;
    public bool $showPause = false;
    public bool $showRemoval = false;
    public bool $showCalendar = false;
    /** @var array<string, mixed>|null */
    #[Locked] public ?array $calendarResult = null;
    public ?string $reportGrade = null;
    public ?string $presentationGrade = null;
    public string $pauseStart = '';
    public string $pauseEnd = '';
    public string $pauseReason = '';

    public function mount(Internship $internship, StudentInternshipContext $context): void
    {
        Gate::authorize('view', $internship);
        $this->internshipId = $internship->id;
        $this->affiliationId = $context->selected(Auth::user())->id;
        if (Gate::allows('viewAdvisorGrades', $internship)) {
            $this->reportGrade = $internship->report_grade;
            $this->presentationGrade = $internship->presentation_grade;
        }
    }

    public function hydrate(StudentInternshipContext $context): void
    {
        abort_unless($context->selected(Auth::user())?->id === $this->affiliationId, 403);
        Gate::authorize('view', $this->record);
    }

    #[Computed]
    public function isOffice(): bool
    {
        return app(StudentInternshipContext::class)->selected(Auth::user())?->type === AffiliationType::InternshipOffice;
    }

    #[Computed]
    public function isStudent(): bool
    {
        return app(StudentInternshipContext::class)->selected(Auth::user())?->type === AffiliationType::Student;
    }

    #[Computed]
    public function isTeachingDirection(): bool
    {
        return app(StudentInternshipContext::class)->selected(Auth::user())?->type === AffiliationType::TeachingDirection;
    }

    #[Computed]
    public function record(): Internship
    {
        $relations = ['course.campus', 'advisorAffiliation.user', 'supervisorAffiliation', 'workplaceAddress.city'];
        if ($this->isOffice || $this->isStudent) {
            $relations = [...$relations, 'internshipRequest', 'pauses' => fn ($query) => $query->orderBy('starts_at')->orderBy('id'),
                'calendarOverrides' => fn ($query) => $query->orderBy('date')->orderBy('id'),
                'workSchedules' => fn ($query) => $query->orderBy('starts_on')];
        }
        if ($this->isOffice) {
            $relations['supervisorEvaluations'] = fn ($query) => $query->with('supervisorAffiliation.user')->latest('id');
        } elseif (! $this->isStudent && ! $this->isTeachingDirection) {
            $relations[] = 'currentSupervisorEvaluation.supervisorAffiliation.user';
        }
        $internship = Internship::query()->with($relations)->findOrFail($this->internshipId);
        Gate::authorize('view', $internship);

        return $internship;
    }

    /** @return array{supervisor_evaluation_weight: int, report_weight: int, presentation_weight: int}|null */
    #[Computed]
    public function gradeWeights(): ?array
    {
        try {
            return app(InternshipGradeCalculator::class)->weights($this->record->internship_type_snapshot['rules'] ?? []);
        } catch (ValidationException) {
            return null;
        }
    }

    public function saveGrades(UpdateAdvisorGrades $update): void
    {
        $update->handle(Auth::user(), $this->record, $this->affiliationId, [
            'report_grade' => $this->reportGrade,
            'presentation_grade' => $this->presentationGrade,
        ]);
        unset($this->record, $this->activityHistory, $this->gradeWeights);
        $this->reportGrade = $this->record->report_grade;
        $this->presentationGrade = $this->record->presentation_grade;
        $this->resetValidation();
        Flux::toast('Notas salvas.', variant: 'success');
    }

    public function releaseEvaluation(ManageSupervisorEvaluation $manage): void
    {
        $manage->handle(Auth::user(), $this->record, $this->affiliationId, 'release');
        unset($this->record);
        Flux::toast('Avaliação solicitada. O aviso ao supervisor foi enfileirado para envio por e-mail.', variant: 'success');
    }

    public function openPause(?int $id = null): void
    {
        Gate::authorize($id === null ? 'createPause' : 'managePauses', $this->record);
        $this->resetValidation();
        $this->reset('pauseStart', 'pauseEnd', 'pauseReason');
        $this->pauseId = $id;
        if ($id !== null) {
            $pause = $this->record->pauses()->findOrFail($id);
            $this->pauseStart = $pause->starts_at->toDateString();
            $this->pauseEnd = $pause->ends_at->toDateString();
            $this->pauseReason = $pause->reason;
        }
        $this->showPause = true;
    }

    public function savePause(ManageInternship $manage): void
    {
        $manage->handle(Auth::user(), $this->record, $this->affiliationId, 'save_pause', [
            'starts_at' => $this->pauseStart, 'ends_at' => $this->pauseEnd, 'reason' => trim($this->pauseReason),
        ], $this->pauseId);
        $this->showPause = false;
        $this->saved('Pausa salva. A situação e a previsão de término foram atualizadas.');
    }

    public function confirmRemoval(int $id): void
    {
        Gate::authorize('managePauses', $this->record);
        $this->record->pauses()->findOrFail($id);
        $this->pauseToRemove = $id;
        $this->resetValidation();
        $this->showRemoval = true;
    }

    public function removePause(ManageInternship $manage): void
    {
        abort_if($this->pauseToRemove === null, 422);
        $manage->handle(Auth::user(), $this->record, $this->affiliationId, 'remove_pause', recordId: $this->pauseToRemove);
        $this->showRemoval = false;
        $this->pauseToRemove = null;
        $this->saved('Pausa removida. A situação e a previsão de término foram atualizadas.');
    }

    public function openCalendar(PreviewInternshipCalendar $preview): void
    {
        abort_unless($this->isOffice, 403);
        $this->calendarResult = null;
        $this->resetValidation(['projected_end_date', 'city_id']);
        $this->calendarResult = $preview->handle(Auth::user(), $this->record);
        $this->showCalendar = true;
    }

    private function saved(string $message): void
    {
        unset($this->record, $this->activityHistory);
        $this->calendarResult = null;
        $this->showCalendar = false;
        $this->resetValidation();
        Flux::toast($message, variant: 'success');
    }

    /** @return LengthAwarePaginator<int, array{id: int, actor: string, subject: string, event: string, occurred_at: string}>|null */
    #[Computed]
    public function activityHistory(): ?LengthAwarePaginator
    {
        Gate::authorize('view', $this->record);
        $query = app(ActivityAccess::class)->forCurrentContext(Auth::user(), app('session.store'));

        return $query === null ? null : app(ActivityHistory::class)->forSubject($this->record, $query);
    }

}; ?>

<div class="w-full space-y-8">
    @if (session('status'))
        <div hidden x-data x-init="$nextTick(() => $flux.toast(@js(session('status')), { variant: 'success' }))"></div>
    @endif
    @php($record = $this->record)
    @if ($errors->has('document'))
        <flux:callout variant="danger" icon="information-circle" heading="Não foi possível preparar o download"><flux:callout.text>{{ $errors->first('document') }}</flux:callout.text></flux:callout>
    @endif
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="$this->isStudent ? route('student-internships.index') : route('internships.index')" wire:navigate>{{ $this->isStudent ? 'Meus estágios' : 'Estágios' }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Nº {{ $record->id }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1">Estágio nº {{ $record->id }}</flux:heading>
                <flux:badge size="sm" :color="match ($record->status) { InternshipStatus::Released, InternshipStatus::InProgress => 'green', InternshipStatus::AwaitingSignatures, InternshipStatus::Paused => 'amber', InternshipStatus::PendingCorrection => 'orange', InternshipStatus::Completed => 'blue', InternshipStatus::Cancelled => 'red', default => 'zinc' }">{{ $record->status->label() }}</flux:badge>
            </div>
            <flux:text class="mt-2">{{ $record->student_snapshot['name'] ?? 'Discente' }} · {{ $record->course->name }}</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            @can('release', [SupervisorEvaluation::class, $record])
                <flux:button variant="primary" wire:click="releaseEvaluation" wire:loading.attr="disabled" wire:target="releaseEvaluation">Solicitar avaliação do supervisor</flux:button>
            @endcan
            <flux:button variant="outline" icon="arrow-left" :href="$this->isStudent ? route('student-internships.index') : route('internships.index')" wire:navigate>Voltar</flux:button>
            @can('update', $record)
                <flux:button icon="pencil-square" :href="route('internships.edit', $record)" wire:navigate>Editar estágio</flux:button>
            @endcan
            @if ($this->isOffice && $record->internshipRequest && auth()->user()->can('review', $record->internshipRequest))
                <flux:button icon="clipboard-document-check" :href="route('internship-review.show', $record->internshipRequest)" wire:navigate>Consultar solicitação</flux:button>
            @elseif ($this->isStudent && $record->internshipRequest && auth()->user()->can('view', $record->internshipRequest))
                <flux:button icon="clipboard-document-list" :href="route('internship-requests.show', $record->internshipRequest)" wire:navigate>Consultar solicitação</flux:button>
            @endif
        </div>
    </div>
    @if ($record->course->campus->deactivated_at !== null)
        <flux:callout icon="information-circle" heading="Campus somente para leitura"><flux:callout.text>O campus está desativado. Os dados permanecem disponíveis para consulta.</flux:callout.text></flux:callout>
    @endif
    @if ($this->isOffice || $this->isStudent)
    <livewire:internships.lifecycle :internship-id="$internshipId" :key="'internship-lifecycle-'.$internshipId.'-'.$record->status->value" @internship-updated="$refresh" />
    <section class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="internship-data-heading">
        <flux:heading id="internship-data-heading" size="lg" level="2">Dados do estágio</flux:heading>
        <dl class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Matrícula</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $record->student_snapshot['registration_number'] ?? 'Não informada' }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Tipo de estágio</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $record->internship_type_snapshot['name'] ?? 'Não informado' }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Concedente</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $record->granting_party_snapshot['name'] ?? 'Não informada' }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Supervisor registrado</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $record->supervisor_snapshot['name'] ?? 'Não informado' }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Orientador</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $record->advisorAffiliation?->user?->name ?? 'Não informado' }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Co-orientador</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $record->co_advisor_name ?? 'Não informado' }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Setor de atuação</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $record->internship_sector ?? 'Não informado' }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Início planejado</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ formatShort($record->planned_start_date) }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Término previsto</dt><dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ formatShort($record->projected_end_date) }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Carga exigida / Margem</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $record->internship_type_snapshot['rules']['required_hours'] ?? '—' }} h / {{ $record->internship_type_snapshot['rules']['safety_margin_days'] ?? '—' }} dias corridos</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Bolsa</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $record->is_remunerated ? formatCurrency((float) $record->grant_value) : 'Não remunerado' }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Auxílio transporte</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $record->transportation_allowance === null ? 'Não informado' : formatCurrency((float) $record->transportation_allowance) }}</dd></div>
        </dl>
        <div><flux:heading level="3">Atividades</flux:heading><flux:text class="mt-2 whitespace-pre-line">{{ $record->activities }}</flux:text></div>
        @if ($record->workplaceAddress)
            <div><flux:heading level="3">Local de estágio registrado</flux:heading><flux:text class="mt-2">{{ $record->workplaceAddress->street }}, {{ $record->workplaceAddress->number }} · {{ $record->workplaceAddress->city?->name }} / {{ $record->workplaceAddress->city?->state?->value }}</flux:text></div>
        @endif
        @if ($this->isOffice)
        <div class="flex flex-wrap gap-3">
            <flux:button icon="calendar-days" wire:click="openCalendar" wire:loading.attr="disabled" wire:target="openCalendar">Ver calendário do estágio</flux:button>
        </div>
        <flux:error name="projected_end_date" />
        <flux:error name="city_id" />
        @endif
    </section>
    <section class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="schedule-heading">
        <flux:heading id="schedule-heading" size="lg" level="2">Jornada registrada</flux:heading>
        <flux:text>A jornada inicial e as vigências já formalizadas são preservadas. Pausas suspendem o cômputo de horas.</flux:text>
        <flux:heading level="3">Jornada inicial desde {{ formatShort($record->planned_start_date) }}{{ $record->workSchedules->first()?->starts_on ? ' até '.formatShort($record->workSchedules->first()->starts_on->copy()->subDay()) : '' }}</flux:heading>
        <div class="grid gap-3 sm:grid-cols-4 lg:grid-cols-7">
            @foreach (['sunday' => 'Domingo', 'monday' => 'Segunda', 'tuesday' => 'Terça', 'wednesday' => 'Quarta', 'thursday' => 'Quinta', 'friday' => 'Sexta', 'saturday' => 'Sábado'] as $day => $label)
                <div wire:key="initial-{{ $day }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700"><flux:text>{{ $label }}</flux:text><span class="font-semibold">{{ $record->weekly_hours[$day] }} h</span></div>
            @endforeach
        </div>
        @foreach ($record->workSchedules as $schedule)
            <div wire:key="schedule-{{ $schedule->id }}" class="space-y-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading level="3">Vigência de {{ formatShort($schedule->starts_on) }} {{ $schedule->ends_on ? 'a '.formatShort($schedule->ends_on) : 'em diante' }}</flux:heading>
                <flux:text>{{ collect($schedule->weekly_hours)->map(fn ($hours, $day) => ['sunday' => 'Dom', 'monday' => 'Seg', 'tuesday' => 'Ter', 'wednesday' => 'Qua', 'thursday' => 'Qui', 'friday' => 'Sex', 'saturday' => 'Sáb'][$day].': '.$hours.' h')->implode(' · ') }}</flux:text>
                <flux:text size="sm">Formalizada pelo aditivo nº {{ $schedule->generated_document_id }}.</flux:text>
            </div>
        @endforeach
    </section>
    @if ($this->isOffice)
    <section class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="administrative-heading">
        <flux:heading id="administrative-heading" size="lg" level="2">Dados administrativos</flux:heading>
        <flux:text class="whitespace-pre-line">{{ $record->observations ?? 'Sem observações.' }}</flux:text>
    </section>
    @endif
    <section class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="pauses-heading">
        <div class="flex flex-wrap items-center justify-between gap-3"><flux:heading id="pauses-heading" size="lg" level="2">Pausas</flux:heading>@can('createPause', $record)<flux:button variant="primary" icon="plus" wire:click="openPause">Registrar pausa</flux:button>@endcan</div>
        @foreach ($record->planned_pauses ?? [] as $key => $pause)
            <div wire:key="planned-pause-{{ $key }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700"><flux:badge size="sm">Prevista na abertura</flux:badge><flux:text class="mt-2">{{ formatShort($pause['starts_at']) }} a {{ formatShort($pause['ends_at']) }} · {{ $pause['reason'] }}</flux:text></div>
        @endforeach
        @forelse ($record->pauses as $pause)
            <div wire:key="pause-{{ $pause->id }}" class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div><flux:heading level="3">{{ formatShort($pause->starts_at) }} a {{ formatShort($pause->ends_at) }}</flux:heading><flux:text class="mt-2 whitespace-pre-line">{{ $pause->reason }}</flux:text></div>
                @can('managePauses', $record)<div class="flex flex-wrap gap-2"><flux:button size="sm" icon="pencil-square" wire:click="openPause({{ $pause->id }})">Editar</flux:button><flux:button size="sm" variant="danger" icon="trash" wire:click="confirmRemoval({{ $pause->id }})">Remover</flux:button></div>@endcan
            </div>
        @empty
            <flux:text>Nenhuma pausa adicional registrada.</flux:text>
        @endforelse
    </section>
    <section class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="overrides-heading">
        <flux:heading id="overrides-heading" size="lg" level="2">Exceções do calendário</flux:heading>
        @forelse ($record->calendarOverrides as $override)
            <div wire:key="override-{{ $override->id }}" class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div><flux:heading level="3">{{ formatShort($override->date) }} · {{ $override->is_working_day ? 'Expediente liberado' : 'Expediente bloqueado' }}</flux:heading><flux:text class="mt-2 whitespace-pre-line">{{ $override->reason }}</flux:text></div>
            </div>
        @empty
            <flux:text>Nenhuma exceção registrada.</flux:text>
        @endforelse
    </section>
    @elseif (! $this->isTeachingDirection)
        <x-internships.academic-details :internship="$record" :weights="$this->gradeWeights" />
        @include('pages.internships.partials.grades', ['internship' => $record, 'weights' => $this->gradeWeights])
    @else
        <section class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="summary-heading">
            <flux:heading id="summary-heading" size="lg" level="2">Acompanhamento do estágio</flux:heading>
            <dl class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ([
                    'Matrícula' => $record->student_snapshot['registration_number'] ?? 'Não informada',
                    'E-mail do discente' => $record->student_snapshot['email'] ?? 'Não informado',
                    'Ano / semestre' => $record->student_snapshot['student_year_semester'] ?? 'Não informado',
                    'Tipo de estágio' => $record->internship_type_snapshot['name'] ?? 'Não informado',
                    'Início previsto' => formatShort($record->planned_start_date),
                    'Término previsto' => formatShort($record->projected_end_date),
                    'Carga horária exigida' => ($record->internship_type_snapshot['rules']['required_hours'] ?? '—').' h',
                    'Orientador' => $record->advisorAffiliation?->user?->name ?? 'Não informado',
                    'Co-orientador' => $record->co_advisor_name ?? 'Não informado',
                    'Concedente' => $record->granting_party_snapshot['name'] ?? 'Não informada',
                    'Cidade do estágio' => $record->workplaceAddress?->city?->name ?? 'Não informada',
                ] as $label => $value)
                    <div wire:key="summary-{{ $loop->index }}"><dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $value }}</dd></div>
                @endforeach
            </dl>
        </section>
    @endif
    @can('viewDocuments', $record)
        <livewire:internships.documents :internship-id="$internshipId" :key="'internship-documents-'.$internshipId.'-'.$record->status->value" @internship-updated="$refresh" />
    @endcan

    @can('viewCalculationHistory', $record)
        <livewire:internships.calculation-history :internship-id="$internshipId" :key="'calculation-history-'.$internshipId" />
    @endcan
    @if ($this->isOffice)
        <section class="space-y-6 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="evaluations-heading">
            <flux:heading id="evaluations-heading" size="lg" level="2">Avaliações do supervisor</flux:heading>
            @forelse ($record->supervisorEvaluations as $evaluation)
                <div wire:key="internship-evaluation-{{ $evaluation->id }}" class="space-y-5 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                    <x-internships.supervisor-evaluation :internship="$record" :evaluation="$evaluation" :weights="$this->gradeWeights" />
                    @can('view', $evaluation)
                        <flux:button :href="route('supervisor-evaluations.show', $evaluation)" wire:navigate>Consultar avaliação</flux:button>
                    @endcan
                </div>
            @empty
                <flux:text>Nenhuma avaliação solicitada para este estágio.</flux:text>
            @endforelse
        </section>
    @else
        @can('viewSupervisorEvaluation', $record)
            @if ($record->currentSupervisorEvaluation?->status === \App\Enums\EvaluationStatus::Approved && $record->currentSupervisorEvaluation->internship_id === $record->id)
                <section class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="evaluations-heading">
                    <flux:heading id="evaluations-heading" size="lg" level="2">Avaliação do supervisor</flux:heading>
                    <x-internships.supervisor-evaluation :internship="$record" :evaluation="$record->currentSupervisorEvaluation" :weights="$this->gradeWeights" />
                </section>
            @endif
        @endcan
    @endif
    @can('viewAny', \Spatie\Activitylog\Models\Activity::class)
        <x-audit.activity-history :activities="$this->activityHistory" description="Alterações registradas neste estágio, nas pausas e nas exceções do calendário." />
    @endcan
    @if ($this->isOffice)
    <x-internship-calendar-preview wire:model.self="showCalendar" :result="$calendarResult" />
    <flux:modal wire:model.self="showPause" class="md:w-lg">
        <form wire:submit="savePause" class="space-y-6">
            <flux:heading size="lg" level="2">{{ $pauseId === null ? 'Registrar pausa' : 'Editar pausa' }}</flux:heading>
            <flux:input type="date" wire:model="pauseStart" label="Início da pausa" required /><flux:error name="starts_at" />
            <flux:input type="date" wire:model="pauseEnd" label="Último dia da pausa" required /><flux:error name="ends_at" />
            <flux:textarea wire:model="pauseReason" label="Motivo" maxlength="1000" required /><flux:error name="reason" /><flux:error name="internship_id" /><flux:error name="projected_end_date" />
            <div class="flex flex-wrap justify-end gap-3"><flux:button type="button" variant="outline" wire:click="$set('showPause', false)">Cancelar</flux:button><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="savePause">Salvar pausa</flux:button></div>
        </form>
    </flux:modal>
    <flux:modal wire:model.self="showRemoval" class="md:w-md">
        <form wire:submit="removePause" class="space-y-6">
            <flux:heading size="lg" level="2">Remover pausa</flux:heading><flux:text>O período voltará a contar conforme a jornada e o calendário. A remoção permanecerá no histórico.</flux:text><flux:error name="projected_end_date" />
            <div class="flex flex-wrap justify-end gap-3"><flux:button type="button" variant="outline" wire:click="$set('showRemoval', false)">Cancelar</flux:button><flux:button type="submit" variant="danger" icon="trash" wire:loading.attr="disabled" wire:target="removePause">Remover pausa</flux:button></div>
        </form>
    </flux:modal>
    @endif
</div>
