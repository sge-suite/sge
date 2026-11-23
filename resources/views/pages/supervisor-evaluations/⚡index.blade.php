<?php

use App\Enums\AffiliationType;
use App\Enums\EvaluationStatus;
use App\Models\SupervisorEvaluation;
use App\Support\PageSize;
use App\Support\StudentInternshipContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Locked]
    public int $affiliationId;

    public const WORKLOAD_OPTIONS = [
        'all' => 'Todas as respostas',
        'met' => 'Cumprida',
        'not_met' => 'Não cumprida',
        'unanswered' => 'Sem resposta',
    ];

    #[Url(except: 'all')]
    public string $workload = 'all';

    public function updatedWorkload(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('workload');
        $this->resetPage();
    }

    #[Computed]
    public function canFilterWorkload(): bool
    {
        return app(StudentInternshipContext::class)->selected(Auth::user())?->type === AffiliationType::InternshipOffice;
    }

    public function mount(StudentInternshipContext $context): void
    {
        Gate::authorize('viewAny', SupervisorEvaluation::class);
        $this->affiliationId = $context->selected(Auth::user())->id;
    }

    public function hydrate(StudentInternshipContext $context): void
    {
        abort_unless($context->selected(Auth::user())?->id === $this->affiliationId, 403, 'O vínculo selecionado mudou. Reabra a lista.');
        Gate::authorize('viewAny', SupervisorEvaluation::class);
    }

    /** @return LengthAwarePaginator<int, SupervisorEvaluation> */
    #[Computed]
    public function evaluations(): LengthAwarePaginator
    {
        Gate::authorize('viewAny', SupervisorEvaluation::class);
        $selected = app(StudentInternshipContext::class)->selected(Auth::user());

        $query = SupervisorEvaluation::query()->with('internship.course')
            ->whereHas('internship', fn ($query) => $query->whereNotNull('evaluation_released_at')
                ->when($selected->type === AffiliationType::Supervisor,
                    fn ($query) => $query->where('supervisor_affiliation_id', $selected->id),
                    fn ($query) => $query->whereHas('course', fn ($query) => $query->where('campus_id', $selected->campus_id))))
            ->when($selected->type === AffiliationType::Supervisor, fn ($query) => $query->where('supervisor_affiliation_id', $selected->id));

        if ($this->canFilterWorkload) {
            match ($this->workload) {
                'met' => $query->where('hours_requirement_met', true),
                'not_met' => $query->where('hours_requirement_met', false),
                'unanswered' => $query->whereNull('hours_requirement_met'),
                default => null,
            };
        }

        return $query->latest('id')->paginate(PageSize::PER_PAGE);
    }
}; ?>

<div class="w-full space-y-8">
    <div><flux:heading size="xl" level="1">Avaliações do supervisor</flux:heading><flux:text class="mt-2">Acompanhe as avaliações solicitadas pelo Setor de Estágio. Selecione uma linha para consultar.</flux:text></div>
    @if ($this->canFilterWorkload)
        <div class="flex flex-wrap items-end gap-4">
            <div class="w-full sm:max-w-xs"><x-select wire:model.live="workload" label="Carga horária informada" :value="$workload" :options="$this::WORKLOAD_OPTIONS" /></div>
            @if ($workload !== 'all')
                <flux:button wire:click="clearFilters">Limpar filtros</flux:button>
            @endif
        </div>
    @endif
    @php($evaluations = $this->evaluations)
    <x-loading-overlay target="workload,clearFilters,gotoPage,nextPage,previousPage" label="Atualizando avaliações…" :dim-while-loading="true">
        @if ($evaluations->isNotEmpty())
            <flux:table :paginate="$evaluations" container:class="[&>ui-table-scroll-area]:max-h-[70vh]">
                <flux:table.columns sticky class="bg-white dark:bg-zinc-800">
                    <flux:table.column>Avaliação / Discente</flux:table.column><flux:table.column class="hidden md:table-cell">Curso / Concedente</flux:table.column><flux:table.column>Situação</flux:table.column><flux:table.column class="hidden lg:table-cell">Último envio</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($evaluations as $evaluation)
                        <flux:table.row :key="'evaluation-'.$evaluation->id" class="group cursor-pointer transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-evaluation-link]').click()">
                            <flux:table.cell class="whitespace-normal">
                                <a data-evaluation-link href="{{ route('supervisor-evaluations.show', $evaluation) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">Nº {{ $evaluation->id }} · {{ $evaluation->internship->student_snapshot['name'] ?? 'Discente' }}</a>
                                <flux:text size="sm">Estágio nº {{ $evaluation->internship_id }} · Matrícula: {{ $evaluation->internship->student_snapshot['registration_number'] ?? 'Não informada' }}</flux:text>
                                <flux:text size="sm">{{ $evaluation->internship->internship_type_snapshot['name'] ?? 'Tipo não informado' }}</flux:text>
                                <div class="space-y-1 md:hidden"><flux:text size="sm">Curso: {{ $evaluation->internship->course->name }}</flux:text><flux:text size="sm">Concedente: {{ $evaluation->internship->granting_party_snapshot['name'] ?? 'Não informada' }}</flux:text></div>
                                <flux:text size="sm" class="lg:hidden">Último envio: {{ formatDateTime($evaluation->submitted_at) }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell class="hidden whitespace-normal md:table-cell">{{ $evaluation->internship->course->name }}<flux:text size="sm">{{ $evaluation->internship->granting_party_snapshot['name'] ?? 'Concedente não informada' }}</flux:text></flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="match ($evaluation->status) { EvaluationStatus::Submitted => 'amber', EvaluationStatus::Returned => 'orange', EvaluationStatus::Approved => 'green', EvaluationStatus::Cancelled => 'red', default => 'zinc' }">{{ $evaluation->status->label() }}</flux:badge>
                                @if ($this->canFilterWorkload)
                                    <flux:text size="sm" class="mt-2">{{ match ($evaluation->hours_requirement_met) { true => 'Carga cumprida', false => 'Carga não cumprida', null => 'Carga sem resposta' } }}</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="hidden lg:table-cell">{{ formatDateTime($evaluation->submitted_at) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700"><flux:icon.clipboard-document-list class="mx-auto mb-4 size-8 text-zinc-400" />@if ($this->canFilterWorkload && $workload !== 'all')
                <flux:heading size="lg">Nenhuma avaliação encontrada</flux:heading><flux:text class="mt-2">Altere o filtro de carga horária para consultar outras avaliações.</flux:text>
            @else
                <flux:heading size="lg">Nenhuma avaliação nesta página</flux:heading><flux:text class="mt-2">As avaliações disponíveis para o vínculo selecionado aparecerão aqui.</flux:text>
            @endif</div>
        @endif
    </x-loading-overlay>
</div>
