<?php

use App\Enums\HolidayScope;
use App\Models\Holiday;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public ?Holiday $holiday = null;

    public string $scope = 'national';

    public string $name = '';

    public string $date = '';

    /** @param array{name?: string, date?: string} $defaults */
    public function mount(?Holiday $holiday = null, array $defaults = []): void
    {
        $this->holiday = $holiday;
        Gate::authorize($holiday === null ? 'create' : 'update', $holiday ?? Holiday::class);
        $scope = old('scope', $holiday?->scope->value ?? 'national');
        $this->scope = is_string($scope) && HolidayScope::tryFrom($scope) !== null ? $scope : 'national';
        $name = old('name', $holiday?->name ?? ($defaults['name'] ?? ''));
        $date = old('date', $holiday?->date?->toDateString() ?? ($defaults['date'] ?? ''));
        $this->name = is_string($name) ? $name : '';
        $this->date = is_string($date) ? $date : '';
    }

    public function boot(): void
    {
        Gate::authorize($this->holiday === null ? 'create' : 'update', $this->holiday ?? Holiday::class);
    }
}; ?>

<div class="grid gap-5 sm:grid-cols-2">
    <flux:input name="name" wire:model="name" label="Nome" :value="$name" maxlength="255" required />
    <flux:input name="date" wire:model="date" type="date" label="Data" :value="$date" required />
    <x-select name="scope" wire:model.live="scope" label="Escopo" :value="$scope" :options="HolidayScope::options()" :invalid="$errors->has('scope')" :error="$errors->first('scope')" required />
    @if ($scope === 'state')
        <x-select name="state_code" label="UF" :value="old('state_code', $holiday?->state_code?->value ?? '')" :options="collect(\App\Enums\BrazilianState::options())->map(fn ($label, $value) => $label.' ('.$value.')')->all()" placeholder="Selecione a UF" :invalid="$errors->has('state_code')" :error="$errors->first('state_code')" searchable required />
    @elseif ($scope === 'municipal')
        <livewire:cities.select :selected-city-id="old('city_id', $holiday?->city_id)" :city-error="$errors->first('city_id')" field-name="city_id" wire:key="holiday-city" />
    @endif
    <div class="sm:col-span-2">
        <flux:error name="holiday" />
    </div>
</div>
