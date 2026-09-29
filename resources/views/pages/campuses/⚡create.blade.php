<?php

use App\Models\Campus;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cadastrar campus')] class extends Component
{
    public function boot(): void
    {
        Gate::authorize('viewAdministration', Campus::class);
        Gate::authorize('create', Campus::class);
    }
}; ?>

<div class="w-full space-y-8">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('campuses.index')" wire:navigate>Campi</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Cadastrar</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <form method="POST" action="{{ route('campuses.store') }}" class="space-y-8" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false" novalidate>
        @csrf
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <flux:heading size="xl" level="1">Cadastrar campus</flux:heading>
                <flux:text class="mt-2">O novo campus ficará ativo e disponível para os cadastros da instituição.</flux:text>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-3">
                <flux:button variant="outline" :href="route('campuses.index')" wire:navigate>Cancelar</flux:button>
                <flux:button variant="primary" type="submit" :loading="false" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                    <flux:icon.loading x-show="submitting" x-cloak class="size-4" />
                    Cadastrar campus
                </flux:button>
            </div>
        </div>
        <livewire:campuses.form-fields />
    </form>
</div>
