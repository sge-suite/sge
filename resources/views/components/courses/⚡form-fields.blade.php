<?php

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Course;
use App\Support\ActiveAffiliationContext;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public ?int $courseId = null;

    #[Locked]
    public ?int $campusId = null;

    /** @var array<string, mixed> */
    public array $values = [];

    public function boot(): void
    {
        Gate::authorize('viewAny', Course::class);
        Gate::authorize($this->courseId === null ? 'create' : 'update', $this->courseId === null ? Course::class : Course::findOrFail($this->courseId));
        $affiliation = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'));
        abort_if($this->campusId !== null && $this->campusId !== $affiliation?->campus_id, 403);
    }

    public function mount(?Course $course = null): void
    {
        $this->courseId = $course?->id;
        Gate::authorize($course === null ? 'create' : 'update', $course ?? Course::class);
        $this->campusId = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'))->campus_id;
        foreach (['name', 'primary_coordinator_affiliation_id', 'secondary_coordinator_affiliation_id'] as $field) {
            $value = old($field, $course?->{$field});
            $this->values[$field] = is_string($value) || is_int($value) ? $value : '';
        }
    }

    /** @return array<int|string, string> */
    #[Computed]
    public function coordinatorOptions(): array
    {
        return ['' => 'Sem coordenador'] + Affiliation::query()->active()
            ->where('campus_id', $this->campusId)
            ->where('type', AffiliationType::Coordinator)
            ->with('user:id,name')->orderBy('id')->get()
            ->mapWithKeys(fn (Affiliation $affiliation): array => [$affiliation->id => $affiliation->user->name.' · '.$affiliation->registration_number])->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function historicalLabels(): array
    {
        if ($this->courseId === null) {
            return [];
        }
        $course = Course::with(['primaryCoordinator.user', 'secondaryCoordinator.user'])->findOrFail($this->courseId);
        Gate::authorize('update', $course);
        $labels = [];
        foreach (['primary' => $course->primaryCoordinator, 'secondary' => $course->secondaryCoordinator] as $slot => $affiliation) {
            $field = $slot.'_coordinator_affiliation_id';
            if ($affiliation !== null && $affiliation->campus_id === $this->campusId
                && (string) $this->values[$field] === (string) $affiliation->id) {
                $labels[$field] = $affiliation->user->name.' · '.$affiliation->registration_number
                    .($affiliation->deactivated_at !== null ? ' (vínculo desativado)' : '');
            }
        }

        return $labels;
    }
}; ?>

<div class="space-y-6 border-t border-zinc-200 pt-6 dark:border-zinc-700">
    <flux:heading size="lg" level="2">Dados do curso</flux:heading>
    <flux:input name="name" :value="$values['name']" wire:model="values.name" label="Nome" required maxlength="255" :invalid="$errors->has('name')" />
    <div class="grid gap-6 md:grid-cols-2">
        <x-select name="primary_coordinator_affiliation_id" wire:model="values.primary_coordinator_affiliation_id" label="Coordenador principal (opcional)"
            :value="$values['primary_coordinator_affiliation_id']" :options="$this->coordinatorOptions" :searchable="true"
            :selected-label="$this->historicalLabels['primary_coordinator_affiliation_id'] ?? ''" :invalid="$errors->has('primary_coordinator_affiliation_id')" :error="$errors->first('primary_coordinator_affiliation_id')" />
        <x-select name="secondary_coordinator_affiliation_id" wire:model="values.secondary_coordinator_affiliation_id" label="Coordenador secundário (opcional)"
            :value="$values['secondary_coordinator_affiliation_id']" :options="$this->coordinatorOptions" :searchable="true"
            :selected-label="$this->historicalLabels['secondary_coordinator_affiliation_id'] ?? ''" :invalid="$errors->has('secondary_coordinator_affiliation_id')" :error="$errors->first('secondary_coordinator_affiliation_id')" />
    </div>
    <flux:text>Os coordenadores são vínculos ativos deste campus e devem ser distintos. Você pode cadastrar o curso sem coordenadores.</flux:text>
    @if (count($this->coordinatorOptions) === 1)
        <flux:callout icon="information-circle" heading="Nenhum coordenador elegível">
            <flux:callout.text>Não há vínculos ativos de Coordenador neste campus. O curso pode ser salvo sem coordenação.</flux:callout.text>
        </flux:callout>
    @endif
</div>
