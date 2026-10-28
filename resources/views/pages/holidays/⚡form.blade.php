<?php

use App\Models\Holiday;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Feriado')] class extends Component
{
    #[Locked]
    public ?Holiday $holiday = null;

    public function mount(?Holiday $holiday = null): void
    {
        $this->holiday = $holiday;
        Gate::authorize($holiday === null ? 'create' : 'update', $holiday ?? Holiday::class);
    }

    public function boot(): void
    {
        Gate::authorize($this->holiday === null ? 'create' : 'update', $this->holiday ?? Holiday::class);
    }
}; ?>

<div class="w-full space-y-8">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('holidays.index')" wire:navigate>Feriados</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $holiday ? 'Editar' : 'Cadastrar' }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <form data-unsaved-changes="native-editor" data-unsaved-initial-dirty="{{ $errors->any() ? 'true' : 'false' }}" method="POST" action="{{ $holiday ? route('holidays.update', $holiday) : route('holidays.store') }}" class="space-y-8"
        x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false" novalidate>
        @csrf
        @if ($holiday)
            @method('PUT')
        @endif
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">{{ $holiday ? 'Editar feriado' : 'Cadastrar feriado' }}</flux:heading>
                <flux:text class="mt-2">
                    @if ($holiday)
                        Origem: {{ $holiday->originLabel() }}. Ajustes manuais serão preservados nas próximas atualizações do calendário.
                    @else
                        Cadastre uma data e informe onde o feriado se aplica.
                    @endif
                </flux:text>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <flux:button variant="outline" :href="route('holidays.index')" wire:navigate>Cancelar</flux:button>
                <flux:button type="submit" variant="primary" :loading="false" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                    <flux:icon.loading x-show="submitting" x-cloak class="size-4" />{{ $holiday ? 'Salvar alterações' : 'Cadastrar feriado' }}
                </flux:button>
            </div>
        </div>
        <livewire:holidays.form-fields :holiday="$holiday" />
    </form>
</div>
