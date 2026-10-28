<?php

use App\Models\Internship;
use App\Support\InternshipCalculationHistory;
use App\Support\PageSize;
use App\Support\StudentInternshipContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

new class extends Component {
    use WithPagination;

    #[Locked] public int $internshipId;
    #[Locked] public int $affiliationId;
    public bool $showCalculation = false;
    /** @var array<string, mixed>|null */
    #[Locked] public ?array $calculationResult = null;

    public function mount(int $internshipId, StudentInternshipContext $context, InternshipCalculationHistory $history): void
    {
        $this->internshipId = $internshipId;
        Gate::authorize('viewCalculationHistory', $this->internship);
        $this->affiliationId = $context->selected(auth()->user())->id;
        $opening = session()->get('open-internship-calculation');
        if (is_array($opening) && ($opening['internship_id'] ?? null) === $this->internshipId
            && ($opening['affiliation_id'] ?? null) === $this->affiliationId && is_int($opening['activity_id'] ?? null)) {
            session()->forget('open-internship-calculation');
            try {
                $this->reproduce($opening['activity_id'], $history);
            } catch (ValidationException $exception) {
                $this->addError('calculation', $exception->errors()['calculation'][0] ?? 'Não foi possível reproduzir o cálculo registrado.');
            }
        }
    }

    public function hydrate(StudentInternshipContext $context): void
    {
        abort_unless($context->selected(auth()->user())?->id === $this->affiliationId, 403);
        Gate::authorize('viewCalculationHistory', $this->internship);
    }

    #[Computed]
    public function internship(): Internship
    {
        $internship = Internship::query()->with('course.campus')->findOrFail($this->internshipId);
        Gate::authorize('viewCalculationHistory', $internship);

        return $internship;
    }

    #[Computed]
    public function calculations(): LengthAwarePaginator
    {
        return Activity::forSubject($this->internship)->where('event', 'end_date_calculated')
            ->latest('id')->paginate(PageSize::PER_PAGE, pageName: 'calculationsPage');
    }

    public function reproduce(int $id, InternshipCalculationHistory $history): void
    {
        Gate::authorize('viewCalculationHistory', $this->internship);
        $this->resetValidation();
        $activity = Activity::forSubject($this->internship)->where('event', 'end_date_calculated')->findOrFail($id);
        $this->calculationResult = $history->replay($activity);
        $this->showCalculation = true;
    }
}; ?>

<section class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="calculation-history-heading">
    <div><flux:heading id="calculation-history-heading" size="lg" level="2">Histórico dos cálculos de término</flux:heading><flux:text class="mt-1">Cada registro preserva a jornada e os dias considerados no cálculo. A reprodução não utiliza alterações posteriores do calendário.</flux:text></div>
    <flux:error name="calculation" />
    @php($calculations = $this->calculations)
    @if ($calculations->isNotEmpty())
        <flux:table :paginate="$calculations">
            <flux:table.columns><flux:table.column>Calculado em</flux:table.column><flux:table.column>Cumprimento da carga</flux:table.column><flux:table.column>Término previsto</flux:table.column><flux:table.column align="end">Ações</flux:table.column></flux:table.columns>
            <flux:table.rows>
                @foreach ($calculations as $calculation)
                    <flux:table.row :key="'calculation-'.$calculation->id">
                        <flux:table.cell>{{ formatDateTime($calculation->created_at) }}</flux:table.cell>
                        <flux:table.cell>{{ formatShort(data_get($calculation->properties, 'calculation.result.completion_date')) }}</flux:table.cell>
                        <flux:table.cell>{{ formatShort(data_get($calculation->properties, 'calculation.result.projected_end_date')) }}</flux:table.cell>
                        <flux:table.cell align="end"><flux:button size="sm" wire:click="reproduce({{ $calculation->id }})" wire:loading.attr="disabled" wire:target="reproduce">Reproduzir cálculo</flux:button></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @else
        <flux:text>Nenhum cálculo com base histórica registrada. Os próximos cálculos salvos aparecerão aqui; registros anteriores permanecem preservados.</flux:text>
    @endif
    <x-internship-calendar-preview wire:model.self="showCalculation" :result="$calculationResult" :historical="true" />
</section>
