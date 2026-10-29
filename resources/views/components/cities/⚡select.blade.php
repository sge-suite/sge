<?php

use App\Enums\BrazilianState;
use App\Models\Campus;
use App\Models\City;
use App\Models\UserPersonalData;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Modelable;
use Livewire\Attributes\Reactive;
use Livewire\Component;

new class extends Component
{
    use \App\Concerns\HandlesSearchFailures;

    protected function searchRetryProperties(): array
    {
        return ['cities' => ['cities']];
    }

    #[Locked]
    public ?int $campusId = null;

    #[Locked]
    public bool $personalData = false;

    public string $state = '';

    #[Modelable]
    public string|int|null $cityId = '';

    #[Reactive]
    public ?string $cityError = '';

    public string $selectionError = '';

    #[Locked]
    public bool $required = true;

    public string $citySearch = '';

    public function hydrate(): void
    {
        $this->authorizeSelection();
    }

    #[\Livewire\Attributes\Locked]
    public string $fieldName = 'address[city_id]';

    public function mount(mixed $selectedCityId = null, ?string $cityError = '', ?int $campusId = null, string $fieldName = 'address[city_id]', bool $personalData = false, bool $required = true): void
    {
        $this->fieldName = $fieldName;
        $this->campusId = $campusId;
        $this->personalData = $personalData;
        $this->required = $required;
        $this->authorizeSelection();
        $key = is_scalar($selectedCityId)
            ? filter_var($selectedCityId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $city = $key !== false
            ? City::find($key)
            : null;

        $this->state = $city?->state->value ?? '';
        $this->cityId = $city === null ? '' : (string) $city->id;
        $this->cityError = $cityError;
    }

    private function authorizeSelection(): void
    {
        if ($this->personalData) {
            Gate::authorize('updateStudentData', UserPersonalData::class);

            return;
        }

        Gate::authorize($this->campusId === null ? 'create' : 'update', $this->campusId === null ? Campus::class : Campus::findOrFail($this->campusId));
    }

    public function updatedState(): void
    {
        $this->cityId = '';
        $this->selectionError = '';
        $this->citySearch = '';
    }

    public function updatedCityId(): void
    {
        if (blank($this->cityId)) {
            $this->cityId = '';
            $this->citySearch = '';
            $this->selectionError = '';

            return;
        }

        if ($this->cityId !== '' && $this->selectedCity === null) {
            $this->cityId = '';
            $this->selectionError = 'Selecione uma cidade da UF informada.';

            return;
        }

        $this->citySearch = '';
        $this->selectionError = '';
    }

    #[Computed]
    public function selectedCity(): ?City
    {
        $key = filter_var($this->cityId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $key !== false
            ? City::query()->forState($this->state)->find($key)
            : null;
    }

    /** @return Collection<int, City> */
    #[Computed]
    public function cities(): Collection
    {
        $state = BrazilianState::tryFrom($this->state);

        $search = mb_substr(trim($this->citySearch), 0, 255);

        if ($state === null || mb_strlen($search) < 2) {
            return new Collection;
        }

        return $this->searchWithRecovery('cities', fn () => City::search($search)->where('state', $state->value)->take(20)->get(), fn () => new Collection);
    }
}; ?>

<div class="grid gap-5 sm:col-span-2 sm:grid-cols-2">
    <x-select wire:model.live="state" label="UF" placeholder="Selecione a UF" :value="$state"
        :options="collect(BrazilianState::options())->map(fn ($label, $value) => $label.' ('.$value.')')->all()" searchable :required="$required" />
    <div>
        <x-select wire:model.live="cityId" :name="$fieldName" label="Cidade" icon="map-pin" placeholder="{{ $state === '' ? 'Selecione primeiro a UF' : 'Selecione a cidade' }}"
            :value="$cityId" :selected-label="$this->selectedCity?->name ?? ''" :options="$this->cities->pluck('name', 'id')->all()"
            :search-unavailable="$this->searchFailed('cities')" search-retry="retrySearch('cities')" search-target="citySearch,retrySearch"
                :empty="mb_strlen(trim($citySearch)) < 2 ? 'Digite pelo menos 2 caracteres para buscar.' : 'Nenhuma cidade encontrada.'"
            :disabled="$state === ''" :invalid="filled($selectionError) || filled($cityError)" :error="$selectionError ?: ($cityError ?? '')" :required="$required" wire:key="city-select-{{ $state }}" wire:loading.attr="disabled" wire:target="state,cityId">
            <x-slot:search>
                <flux:input name="" wire:model.live.debounce.500ms="citySearch" wire:ignore.self placeholder="Buscar cidade…" aria-label="Buscar cidade" icon="magnifying-glass" size="sm" maxlength="255"
                    role="combobox" aria-autocomplete="list" x-bind:aria-expanded="open" x-bind:aria-controls="listElement()?.id" x-bind:aria-activedescendant="activeId()" />
                <span wire:loading wire:target="citySearch" class="block px-2 py-1 text-xs text-zinc-500" role="status">Buscando cidades…</span>
            </x-slot:search>
        </x-select>
    </div>
</div>
