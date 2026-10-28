<?php

use App\Models\HolidayRecurrence;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Recorrência municipal')] class extends Component
{
    #[Locked]
    public ?HolidayRecurrence $holidayRecurrence = null;

    public function mount(?HolidayRecurrence $holidayRecurrence = null): void
    {
        $this->holidayRecurrence = $holidayRecurrence;
        Gate::authorize($holidayRecurrence === null ? 'create' : 'update', $holidayRecurrence ?? HolidayRecurrence::class);
    }

    public function boot(): void
    {
        Gate::authorize($this->holidayRecurrence ? 'update' : 'create', $this->holidayRecurrence ?? HolidayRecurrence::class);
    }
}; ?>

<div class="w-full space-y-8">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('holidays.index')" wire:navigate>Feriados</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('holiday-recurrences.index')" wire:navigate>Recorrências municipais</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $holidayRecurrence ? 'Editar' : 'Cadastrar' }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <form data-unsaved-changes="native-editor" data-unsaved-initial-dirty="{{ $errors->any() ? 'true' : 'false' }}" method="POST" action="{{ $holidayRecurrence ? route('holiday-recurrences.update', $holidayRecurrence) : route('holiday-recurrences.store') }}" class="space-y-8"
        x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false" novalidate>
        @csrf
        @if ($holidayRecurrence)
            @method('PUT')
        @endif
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">{{ $holidayRecurrence ? 'Editar recorrência municipal' : 'Cadastrar recorrência municipal' }}</flux:heading>
                <flux:text class="mt-2">
                    {{ $holidayRecurrence ? 'Alterações afetam os próximos anos e preservam o ano atual, os anteriores e ajustes manuais.' : 'Cadastre um feriado municipal que se repete no mesmo dia e mês.' }}
                </flux:text>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <flux:button variant="outline" :href="route('holiday-recurrences.index')" wire:navigate>Cancelar</flux:button>
                <flux:button type="submit" variant="primary" :loading="false" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                    <flux:icon.loading x-show="submitting" x-cloak class="size-4" />{{ $holidayRecurrence ? 'Salvar alterações' : 'Cadastrar recorrência' }}
                </flux:button>
            </div>
        </div>
        <div class="space-y-5">
            <flux:input name="name" label="Nome" :value="old('name', $holidayRecurrence?->name)" maxlength="255" required />
            <livewire:cities.select :selected-city-id="old('city_id', $holidayRecurrence?->city_id)" :city-error="$errors->first('city_id')" field-name="city_id" />
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:input name="day" label="Dia" type="number" min="1" max="31" :value="old('day', $holidayRecurrence?->day)" required />
                <x-select name="month" label="Mês" :value="old('month', $holidayRecurrence?->month ?? '')"
                    :options="[1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro']"
                    placeholder="Selecione o mês" :invalid="$errors->has('month')" :error="$errors->first('month')" required />
                <flux:input name="starts_year" label="Ano inicial (opcional)" type="number" min="1900" max="2199" :value="old('starts_year', $holidayRecurrence?->starts_year)" description:trailing="Em branco, sem limite de início." />
                <flux:input name="ends_year" label="Ano final (opcional)" type="number" min="1900" max="2199" :value="old('ends_year', $holidayRecurrence?->ends_year)" description:trailing="Informe para encerrar a recorrência após esse ano." />
            </div>
            <flux:text>Datas em 29 de fevereiro serão geradas apenas nos anos bissextos.</flux:text>
            <flux:textarea name="notes" label="Referência ou observação (opcional)">{{ old('notes', $holidayRecurrence?->notes) }}</flux:textarea>
            <flux:error name="recurrence" />
        </div>
    </form>
</div>
