<?php

use App\Enums\BrazilianState;
use App\Models\Campus;
use App\Models\City;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $state = '';

    public string $cityId = '';

    public string $cityError = '';

    public string $citySearch = '';

    public function boot(): void
    {
        Gate::authorize('viewAdministration', Campus::class);
    }

    public function mount(mixed $selectedCityId = null, string $cityError = ''): void
    {
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

    public function updatedState(): void
    {
        $this->cityId = '';
        $this->cityError = '';
        $this->citySearch = '';
    }

    public function updatedCityId(): void
    {
        if ($this->cityId !== '' && $this->selectedCity === null) {
            $this->cityId = '';
            $this->cityError = 'Selecione uma cidade da UF informada.';

            return;
        }

        $this->citySearch = '';
        $this->cityError = '';
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

        return City::search($search)->where('state', $state->value)->take(20)->get();
    }
}; ?>

<div class="grid gap-5 sm:col-span-2 sm:grid-cols-2">
    <x-select wire:model.live="state" label="UF" placeholder="Selecione a UF" :value="$state"
        :options="collect(BrazilianState::options())->map(fn ($label, $value) => $label.' ('.$value.')')->all()" searchable required />
    <div>
        <x-select wire:model.live="cityId" name="address[city_id]" label="Cidade" icon="map-pin" placeholder="{{ $state === '' ? 'Selecione primeiro a UF' : 'Selecione a cidade' }}"
            :value="$cityId" :selected-label="$this->selectedCity?->name ?? ''" :options="$this->cities->pluck('name', 'id')->all()"
            :empty="mb_strlen(trim($citySearch)) < 2 ? 'Digite pelo menos 2 caracteres para buscar.' : 'Nenhuma cidade encontrada.'"
            :disabled="$state === ''" :invalid="$cityError !== ''" :error="$cityError" required wire:key="city-select-{{ $state }}" wire:loading.attr="disabled" wire:target="state,cityId">
            <x-slot:search>
                <flux:input :name="null" wire:model.live.debounce.500ms="citySearch" x-bind:disabled="!open" placeholder="Buscar cidade…" aria-label="Buscar cidade" icon="magnifying-glass" size="sm" maxlength="255"
                    role="combobox" aria-autocomplete="list" x-bind:aria-expanded="open" x-bind:aria-controls="$refs.list.id" x-bind:aria-activedescendant="activeId()" />
                <span wire:loading wire:target="citySearch" class="block px-2 py-1 text-xs text-zinc-500" role="status">Buscando cidades…</span>
            </x-slot:search>
        </x-select>
    </div>
</div>
