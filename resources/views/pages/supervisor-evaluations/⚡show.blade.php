<?php

use App\Actions\ManageSupervisorEvaluation;
use App\Actions\PrepareSupervisorEvaluationReference;
use App\Concerns\SupervisorProfessionalDataValidationRules;
use App\Enums\AffiliationType;
use App\Enums\EducationLevel;
use App\Enums\EvaluationConcept;
use App\Enums\EvaluationStatus;
use App\Models\SupervisorEvaluation;
use App\Support\StudentInternshipContext;
use App\Support\ActivityAccess;
use App\Support\ActivityHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use SupervisorProfessionalDataValidationRules;
    use WithPagination;

    public const STEPS = [0 => 'Carga horária', 1 => 'Formação e atuação', 2 => 'Critérios de avaliação',
        3 => 'Comentários', 4 => 'Revisar e enviar'];

    #[Locked]
    public int $step = 0;

    public int $navigationStep = 0;
    #[Locked]
    public string $resumeNotice = '';

    public bool $showCancellation = false;
    #[Locked]
    public int $evaluationId;

    #[Locked]
    public int $affiliationId;

    /** @var array<string, mixed> */
    public array $values = [];

    public string $reviewNotes = '';

    public string $cancellationReason = '';

    public function mount(SupervisorEvaluation $supervisorEvaluation, StudentInternshipContext $context): void
    {
        Gate::authorize('view', $supervisorEvaluation);
        $this->evaluationId = $supervisorEvaluation->id;
        $this->affiliationId = $context->selected(Auth::user())->id;
        $this->loadValues();
        if (! Gate::allows('update', $this->record)) {
            $this->step = $this->navigationStep = 4;
        } else {
            $statuses = $this->stepStatuses;
            $unfinished = array_keys(array_filter($statuses, fn (array $status): bool => ! $status['review'] && ! $status['complete']));
            $returned = $this->record->status === EvaluationStatus::Returned;
            $this->step = $this->navigationStep = $unfinished[0] ?? ($returned ? 0 : 4);
            $this->resumeNotice = $returned ? 'Esta avaliação foi devolvida. Confira a orientação do Setor e revise suas respostas antes de reenviar.'
                : ($this->step === 4 ? 'As etapas obrigatórias estão preenchidas. Confira a revisão antes de enviar a avaliação.'
                    : 'Abrimos na primeira etapa incompleta, considerando as respostas salvas. Seu preenchimento anterior foi mantido.');
        }
    }

    public function hydrate(StudentInternshipContext $context): void
    {
        abort_unless($context->selected(Auth::user())?->id === $this->affiliationId, 403, 'O vínculo selecionado mudou. Reabra a avaliação.');
        Gate::authorize('view', $this->record);
    }

    #[Computed]
    public function record(): SupervisorEvaluation
    {
        $evaluation = SupervisorEvaluation::query()->with(['internship.course.campus', 'supervisorAffiliation.user'])->findOrFail($this->evaluationId);
        Gate::authorize('view', $evaluation);

        return $evaluation;
    }

    /** @return LengthAwarePaginator<int, array{id: int, actor: string, subject: string, event: string, occurred_at: string}>|null */
    #[Computed]
    public function activityHistory(): ?LengthAwarePaginator
    {
        Gate::authorize('view', $this->record);
        if (app(StudentInternshipContext::class)->selected(Auth::user())?->type !== AffiliationType::InternshipOffice) {
            return null;
        }
        $query = app(ActivityAccess::class)->forCurrentContext(Auth::user(), app('session.store'));

        return $query === null ? null : app(ActivityHistory::class)->forSubject($this->record, $query);
    }

    public function updatedValues(mixed $value, string $key): void
    {
        Gate::authorize('update', $this->record);
        $this->resumeNotice = '';
        unset($this->stepStatuses);
        $this->resetValidation($key);
        if ($key === 'hours_requirement_met') {
            $this->values['estimated_hours_remaining'] = null;
            unset($this->steps);
            if (! array_key_exists($this->step, $this->steps)) {
                $this->step = $this->navigationStep = 0;
            }
            if ((string) $value !== '1') {
                foreach (['has_academic_background', 'job_role', 'training_course', 'education_level', 'experience_time', ...array_keys(SupervisorEvaluation::criteria()), ...array_keys(SupervisorEvaluation::comments())] as $field) {
                    $this->values[$field] = null;
                }
            } elseif ($this->values['has_academic_background'] === null) {
                $reference = $this->record->internship->supervisor_snapshot['has_academic_background'] ?? null;
                if (is_bool($reference)) {
                    $this->copyReference($reference);
                }
            }
        } elseif ($key === 'has_academic_background' && in_array($value, [true, false, 1, 0, '1', '0'], true)) {
            $jobRole = $this->values['job_role'] ?? null;
            $this->copyReference((bool) $value);
            if (filled($jobRole)) {
                $this->values['job_role'] = $jobRole;
            }
        }
    }

    private function copyReference(bool $academic): void
    {
        $this->values = array_replace($this->values, app(PrepareSupervisorEvaluationReference::class)->handle(
            Auth::user(), $this->affiliationId, $this->record->internship_id, $academic));
        $this->values['has_academic_background'] = (string) (int) $academic;
    }

    public function save(ManageSupervisorEvaluation $manage): void
    {
        $this->persist($manage, 'save');
    }

    public function submit(ManageSupervisorEvaluation $manage): void
    {
        Gate::authorize('update', $this->record);
        try {
            foreach (array_keys($this->steps) as $step) {
                $this->validateStep($step);
            }
        } catch (ValidationException $exception) {
            $this->showValidationErrors($exception);

            return;
        }
        $this->persist($manage, 'submit');
    }

    /** @return array<int, string> */
    #[Computed]
    public function steps(): array
    {
        return (string) ($this->values['hours_requirement_met'] ?? '') === '0'
            ? array_intersect_key(self::STEPS, [0 => true, 4 => true]) : self::STEPS;
    }

    /** @return array<int, array{title: string, editable: bool, complete: bool, needsCorrection: bool, optional: bool, review: bool, ready: bool}> */
    #[Computed]
    public function stepStatuses(): array
    {
        $editable = Gate::allows('update', $this->record);
        $statuses = [];
        foreach ($this->steps as $index => $title) {
            $review = $index === 4;
            $complete = false;
            if ($editable && ! $review) {
                try {
                    $this->validateStep($index);
                    $complete = true;
                } catch (ValidationException) {
                    $complete = false;
                }
            }
            $statuses[$index] = ['title' => $title, 'editable' => $editable, 'complete' => $complete,
                'needsCorrection' => $editable && ! $review && $this->record->status === EvaluationStatus::Returned,
                'optional' => $index === 3, 'review' => $review, 'ready' => false];
        }
        $statuses[4]['ready'] = $editable && ! collect($statuses)
            ->contains(fn (array $status): bool => ! $status['review'] && ! $status['complete']);

        return $statuses;
    }

    /** @return array<string, string> */
    #[Computed]
    public function conceptOptions(): array
    {
        $rules = $this->record->internship->internship_type_snapshot['rules'] ?? [];

        return collect(EvaluationConcept::cases())->mapWithKeys(fn (EvaluationConcept $concept): array => [
            $concept->value => $concept->label().' ('.($concept === EvaluationConcept::Excellent
                ? ($rules['supervisor_evaluation_weight'] ?? '—') : ($rules[$concept->internshipTypeValueAttribute()] ?? '—')).')',
        ])->all();
    }

    public function updatedNavigationStep(int $step): void
    {
        $this->goTo($step);
        $this->navigationStep = $this->step;
    }

    public function goTo(int $step): void
    {
        Gate::authorize('view', $this->record);
        abort_unless(array_key_exists($step, $this->steps), 404);
        $this->resumeNotice = '';
        if ($step === $this->step) {
            return;
        }
        if (Gate::allows('update', $this->record) && $step > $this->step) {
            try {
                foreach (array_keys($this->steps) as $previous) {
                    if ($previous < $step) {
                        $this->validateStep($previous);
                    }
                }
            } catch (ValidationException $exception) {
                $this->showValidationErrors($exception);

                return;
            }
            if (! $this->persist(app(ManageSupervisorEvaluation::class), 'save', announce: false)) {
                return;
            }
        }
        $this->step = $this->navigationStep = $step;
        $this->resetValidation();
        $this->dispatch('evaluation-step-changed');
    }

    private function validateStep(int $step): void
    {
        $data = array_map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $this->values);
        $hoursMet = (string) ($data['hours_requirement_met'] ?? '') === '1';
        $choice = $data['has_academic_background'] ?? null;
        $academic = in_array($choice, [true, false, 1, 0, '1', '0'], true) ? (bool) $choice : null;
        $rules = match ($step) {
            0 => ['hours_requirement_met' => ['required', 'boolean'],
                'estimated_hours_remaining' => [Rule::requiredIf(! $hoursMet), Rule::prohibitedIf($hoursMet), 'nullable', 'integer', 'min:1',
                    'max:'.min(65535, (int) ($this->record->internship->internship_type_snapshot['rules']['required_hours'] ?? 0))]],
            1 => $this->supervisorQualificationRules(false, $academic),
            2 => array_fill_keys(array_keys(SupervisorEvaluation::criteria()), ['required', Rule::in(EvaluationConcept::values())]),
            3 => array_fill_keys(array_keys(SupervisorEvaluation::comments()), ['nullable', 'string', 'max:5000']),
            default => [],
        };
        Validator::make($data, $rules, attributes: [
            'hours_requirement_met' => 'cumprimento da carga horária', 'estimated_hours_remaining' => 'horas faltantes',
            'job_role' => 'cargo ou função', 'has_academic_background' => 'qualificação relacionada ao estágio',
            'training_course' => 'curso de formação', 'education_level' => 'nível de formação', 'experience_time' => 'experiência profissional',
            ...SupervisorEvaluation::criteria(), ...SupervisorEvaluation::comments(),
        ])->validate();
    }

    private function showValidationErrors(ValidationException $exception): void
    {
        $field = array_key_first($exception->errors());
        $step = match (true) {
            in_array($field, ['hours_requirement_met', 'estimated_hours_remaining'], true) => 0,
            in_array($field, ['job_role', 'has_academic_background', 'training_course', 'education_level', 'experience_time'], true) => 1,
            array_key_exists($field, SupervisorEvaluation::criteria()) => 2,
            array_key_exists($field, SupervisorEvaluation::comments()) => 3,
            default => 4,
        };
        $this->step = $this->navigationStep = array_key_exists($step, $this->steps) ? $step : 0;
        $this->setErrorBag($exception->errors());
        $this->dispatch('evaluation-step-changed');
    }

    public function openCancellation(): void
    {
        Gate::authorize('cancel', $this->record);
        $this->reset('cancellationReason');
        $this->resetValidation('cancellation_reason');
        $this->showCancellation = true;
    }

    public function approve(ManageSupervisorEvaluation $manage): void
    {
        $this->persist($manage, 'approve', ['review_notes' => $this->reviewNotes]);
    }

    public function returnEvaluation(ManageSupervisorEvaluation $manage): void
    {
        $this->persist($manage, 'return', ['review_notes' => $this->reviewNotes]);
    }

    public function cancel(ManageSupervisorEvaluation $manage): void
    {
        $this->persist($manage, 'cancel', ['cancellation_reason' => $this->cancellationReason]);
    }

    /** @param array<string, mixed>|null $data */
    private function persist(ManageSupervisorEvaluation $manage, string $operation, ?array $data = null, bool $announce = true): bool
    {
        try {
            $manage->handle(Auth::user(), $this->record->internship, $this->affiliationId, $operation, $data ?? $this->values, $this->evaluationId);
        } catch (ValidationException $exception) {
            if (in_array($operation, ['save', 'submit'], true)) {
                $this->showValidationErrors($exception);
            } else {
                $this->setErrorBag($exception->errors());
            }

            return false;
        }
        unset($this->record, $this->steps, $this->stepStatuses, $this->conceptOptions, $this->activityHistory);
        $this->loadValues();
        $this->resetValidation();
        if (! Gate::allows('update', $this->record)) {
            $this->step = $this->navigationStep = 4;
        }
        $this->showCancellation = false;
        $this->dispatch('form-saved', scope: 'supervisor-evaluation', savedAt: formatDateTime(now()));
        if ($announce) {
            Flux::toast(match ($operation) {
                'save' => 'Rascunho salvo.', 'submit' => 'Avaliação enviada para análise.',
                default => 'Avaliação atualizada.',
            }, variant: 'success');
        }

        return true;
    }

    private function loadValues(): void
    {
        $this->values = $this->record->only(['hours_requirement_met', 'estimated_hours_remaining', 'has_academic_background',
            'job_role', 'training_course', 'education_level', 'experience_time', ...array_keys(SupervisorEvaluation::criteria()), ...array_keys(SupervisorEvaluation::comments())]);
        $this->values['hours_requirement_met'] = $this->record->hours_requirement_met === null ? '' : (string) (int) $this->record->hours_requirement_met;
        $this->values['has_academic_background'] = $this->record->has_academic_background === null ? null : (string) (int) $this->record->has_academic_background;
        $this->values['education_level'] = $this->record->education_level?->value;
    }
}; ?>

<div class="w-full space-y-8" data-unsaved-changes="supervisor-evaluation" data-unsaved-models='["values", "reviewNotes"]' data-unsaved-actions='["save", "goTo", "submit", "approve", "returnEvaluation", "cancel"]' x-data x-on:evaluation-step-changed.window="$nextTick(() => requestAnimationFrame(() => { const heading = document.getElementById('evaluation-stage-title'); heading?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'start' }); heading?.focus({ preventScroll: true }); }))">
    @php
        $evaluation = $this->record;
        $internship = $evaluation->internship;
        $officeEmail = $internship->course->campus->internship_office_email;
        $editable = auth()->user()->can('update', $evaluation);
        $officeView = app(StudentInternshipContext::class)->selected(Auth::user())?->type === AffiliationType::InternshipOffice;
        $rules = $internship->internship_type_snapshot['rules'] ?? [];
        $steps = $this->steps;
        $stepIds = array_keys($steps);
        $position = array_search($step, $stepIds, true);
        $stepStatuses = $this->stepStatuses;
    @endphp
    @if ($editable || $officeView)<x-form-save-status :saved-at="$evaluation->updated_at" />@endif
    <flux:breadcrumbs><flux:breadcrumbs.item :href="route('supervisor-evaluations.index')" wire:navigate>Avaliações do supervisor</flux:breadcrumbs.item><flux:breadcrumbs.item>Avaliação nº {{ $evaluationId }}</flux:breadcrumbs.item></flux:breadcrumbs>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex min-w-0 items-start gap-3">
            @unless ($officeView)<flux:avatar icon="clipboard-document-list" icon-variant="outline" class="shrink-0 bg-brand/10 text-brand [&>svg]:opacity-100" />@endunless
            <div><flux:heading size="xl" level="1">{{ $officeView ? 'Avaliação nº '.$evaluationId : ($editable ? 'Vamos avaliar o estágio' : 'Avaliação do supervisor') }}</flux:heading><flux:text class="mt-2">{{ $officeView ? 'Consulte as respostas do supervisor e registre a análise da avaliação.' : ($editable ? 'Preencha por etapas. Você pode salvar e continuar depois.' : 'Confira as respostas e acompanhe a análise do Setor de Estágio.') }}</flux:text></div>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <flux:badge>{{ $evaluation->status->label() }}</flux:badge>
            <flux:button variant="outline" icon="arrow-left" :href="route('supervisor-evaluations.index')" wire:navigate>Voltar</flux:button>
            @if ($officeView)
                @can('view', $internship)<flux:button icon="briefcase" :href="route('internships.show', $internship)" wire:navigate>Ver estágio nº {{ $internship->id }}</flux:button>@endcan
            @endif
            @can('cancel', $evaluation)<flux:button variant="danger" wire:click="openCancellation">Cancelar avaliação</flux:button>@endcan
        </div>
    </div>
    @unless ($officeView)
    <div class="space-y-2">
        <flux:heading level="2">{{ $internship->student_snapshot['name'] ?? 'Discente' }} · {{ $internship->course->name }}</flux:heading>
        <flux:text>{{ $internship->granting_party_snapshot['name'] ?? 'Concedente' }} · {{ $internship->internship_type_snapshot['name'] ?? 'Estágio' }}</flux:text>
        <flux:text size="sm">Solicitada em {{ formatDateTime($internship->evaluation_released_at) }} · Período previsto: {{ formatShort($internship->planned_start_date) }} a {{ formatShort($internship->projected_end_date) }}</flux:text>
    </div>
    @if (filled($officeEmail))
        <flux:callout icon="information-circle" heading="Dúvidas sobre a avaliação?">
            <flux:callout.text>
                Se tiver dúvidas sobre o formulário ou o formato da avaliação, entre em contato com o Setor de Estágio pelo e-mail:
                <x-copy-email :email="$officeEmail" />
            </flux:callout.text>
        </flux:callout>
    @endif
    @endunless
    @if ($evaluation->review_notes)
        <flux:callout :variant="$editable ? 'warning' : 'secondary'" icon="information-circle" heading="Orientação do Setor de Estágio"><flux:callout.text class="whitespace-pre-line">{{ $evaluation->review_notes }}</flux:callout.text></flux:callout>
    @elseif (! $editable && $evaluation->status === \App\Enums\EvaluationStatus::Submitted)
        <flux:callout heading="Avaliação enviada para análise"><flux:callout.text>As respostas estão disponíveis para consulta. A edição será reaberta se o Setor solicitar correções.</flux:callout.text></flux:callout>
    @endif
    @if ($evaluation->cancellation_reason)
        <flux:callout heading="Motivo do cancelamento"><flux:callout.text class="whitespace-pre-line">{{ $evaluation->cancellation_reason }}</flux:callout.text></flux:callout>
    @endif
    @if ($internship->current_supervisor_evaluation_id === $evaluation->id && app(StudentInternshipContext::class)->selected(Auth::user())?->type === AffiliationType::InternshipOffice)
        <section class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <flux:heading size="lg" level="2">Avaliação aprovada vigente</flux:heading>
            <dl class="grid gap-5 sm:grid-cols-2">
                <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Nota do supervisor (máx. {{ $rules['supervisor_evaluation_weight'] ?? '—' }})</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $internship->supervisor_grade ?? 'Pendente' }}</dd></div>
                <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Nota consolidada (máx. 10)</dt><dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $internship->consolidated_grade ?? 'Pendente' }}</dd></div>
            </dl>
        </section>
    @endif
    @if ($officeView)
        @if ($errors->any())<flux:callout variant="danger" heading="Confira a decisão"><ul>@foreach ($errors->all() as $index => $error)<li wire:key="evaluation-review-error-{{ $index }}">{{ $error }}</li>@endforeach</ul></flux:callout>@endif
        <div class="space-y-6 rounded-xl border border-zinc-200 p-6 text-base dark:border-zinc-700 [&_dd]:text-base [&_dd]:font-medium">
            <section class="space-y-4" aria-labelledby="evaluation-details-heading">
                <flux:heading id="evaluation-details-heading" level="2">Dados da avaliação</flux:heading>
                <dl class="grid gap-5 sm:grid-cols-2 2xl:grid-cols-3">
                    @foreach (['Discente' => $internship->student_snapshot['name'] ?? null, 'Matrícula' => $internship->student_snapshot['registration_number'] ?? null,
                        'Curso' => $internship->course->name, 'Concedente' => $internship->granting_party_snapshot['name'] ?? null,
                        'Supervisor responsável' => $evaluation->supervisorAffiliation?->user?->name, 'Tipo de estágio' => $internship->internship_type_snapshot['name'] ?? null,
                        'Solicitada em' => formatDateTime($internship->evaluation_released_at), 'Último envio' => formatDateTime($evaluation->submitted_at),
                        'Revisada em' => formatDateTime($evaluation->reviewed_at), 'Início previsto' => formatShort($internship->planned_start_date),
                        'Término previsto' => formatShort($internship->projected_end_date)] as $label => $value)
                        <div wire:key="evaluation-detail-{{ $loop->index }}"><dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="mt-1 break-words">{{ filled($value) ? $value : 'Não informado' }}</dd></div>
                    @endforeach
                </dl>
            </section>
            <flux:separator />
            @include('pages.supervisor-evaluations.partials.review')
        </div>
    @else
    <x-form-step-progress :steps="$stepStatuses" :current="$step" :editable="$editable" context="avaliação" />
    @if ($resumeNotice)
        <flux:callout icon="information-circle" heading="Continue o preenchimento"><flux:callout.text>{{ $resumeNotice }}</flux:callout.text></flux:callout>
    @endif

    <div class="grid items-start gap-8 lg:grid-cols-[15rem_minmax(0,1fr)]">
        <x-form-step-navigation :steps="$stepStatuses" :current="$step" label="Etapas da avaliação" />
        <form wire:submit="submit" class="min-w-0 space-y-6" novalidate>
            <x-loading-overlay target="goTo,navigationStep,save,submit,values.hours_requirement_met,values.has_academic_background" label="Atualizando avaliação…" :dim-while-loading="true">
                <section wire:key="evaluation-stage-{{ $step }}" aria-labelledby="evaluation-stage-title" class="space-y-6">
                    <flux:heading id="evaluation-stage-title" tabindex="-1" class="scroll-mt-6 outline-none" level="2" size="lg">{{ $steps[$step] }}</flux:heading>
                    @if ($errors->any())<flux:callout variant="danger" heading="Confira os campos indicados antes de continuar."><flux:callout.text>{{ $errors->first() }}</flux:callout.text></flux:callout>@endif
                    @if ($step === 4)
                        @include('pages.supervisor-evaluations.partials.review')
                    @else
                        @include('pages.supervisor-evaluations.partials.steps')
                    @endif
                </section>
            </x-loading-overlay>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <flux:button type="button" icon="arrow-left" wire:click="goTo({{ $stepIds[max(0, $position - 1)] }})" :disabled="$position === 0" wire:loading.attr="disabled">Etapa anterior</flux:button>
                <div class="flex flex-wrap gap-3">
                    @if ($editable)<flux:button type="button" wire:click="save" wire:loading.attr="disabled">Salvar rascunho</flux:button>@endif
                    @if ($step !== 4)<flux:button type="button" variant="primary" icon:trailing="arrow-right" wire:click="goTo({{ $stepIds[$position + 1] }})" wire:loading.attr="disabled">{{ $editable ? 'Salvar e continuar' : 'Próxima etapa' }}</flux:button>
                    @elseif ($editable)<flux:button type="submit" variant="primary" wire:loading.attr="disabled">Enviar avaliação</flux:button>@endif
                </div>
            </div>
        </form>
    </div>
    @endif
    @can('review', $evaluation)
        @if ($officeView || $step === 4)
        <section class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading size="lg" level="2">Revisão do Setor de Estágio</flux:heading>
            @if ($evaluation->hours_requirement_met !== true)<flux:callout variant="warning" heading="Carga horária pendente">Devolva a avaliação com justificativa. Esta resposta não pode ser aprovada.</flux:callout>@endif
            <flux:textarea wire:model="reviewNotes" label="Justificativa da revisão" maxlength="5000" /><flux:error name="review_notes" /><flux:error name="grade_weights" /><flux:error name="supervisor_evaluation" /><flux:error name="lifecycle" />
            @if ($evaluation->hours_requirement_met === true)<flux:text>A aprovação recalcula as notas e conclui o estágio, pois a carga horária integral está confirmada.</flux:text>@endif
            <div class="flex flex-wrap gap-3"><flux:button wire:click="returnEvaluation" wire:loading.attr="disabled">Devolver com justificativa</flux:button>@if ($evaluation->hours_requirement_met === true)<flux:button variant="primary" wire:click="approve" wire:loading.attr="disabled">Aprovar avaliação</flux:button>@endif</div>
            <flux:text size="sm" wire:loading wire:target="approve,returnEvaluation">Registrando revisão…</flux:text>
        </section>
        @endif
    @endcan
    @if ($officeView)<x-audit.activity-history :activities="$this->activityHistory" description="Solicitação, preenchimento, envio e decisões registradas nesta avaliação." />@endif
    @can('cancel', $evaluation)
    <flux:modal wire:model.self="showCancellation" class="md:w-xl">
        <form wire:submit="cancel" class="space-y-6" data-unsaved-changes="dialog-cancel" data-unsaved-models='["cancellationReason"]' data-unsaved-actions='["cancel"]'>
            <x-form-save-status />
            <flux:heading size="lg" level="2">Cancelar avaliação</flux:heading><flux:text>O registro e suas respostas permanecem no histórico. Informe o motivo para confirmar.</flux:text>
            <flux:textarea wire:model="cancellationReason" label="Motivo do cancelamento" maxlength="5000" required /><flux:error name="cancellation_reason" />
            <div class="flex flex-wrap justify-end gap-3"><flux:button type="button" wire:click="$set('showCancellation', false)">Voltar</flux:button><flux:button type="submit" variant="danger" wire:loading.attr="disabled">Cancelar avaliação</flux:button></div>
        </form>
    </flux:modal>
    @endcan
</div>
