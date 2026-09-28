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

<div class="mx-auto w-full max-w-3xl space-y-8">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('campuses.index')" wire:navigate>Campi</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Cadastrar</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <div>
        <flux:heading size="xl" level="1">Cadastrar campus</flux:heading>
        <flux:text class="mt-2">O novo campus ficará ativo e disponível para os cadastros da instituição.</flux:text>
    </div>
    <form method="POST" action="{{ route('campuses.store') }}" class="space-y-8" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false" novalidate>
        @csrf
        <livewire:campuses.form-fields />
        <div class="flex flex-wrap items-center gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <flux:button variant="primary" type="submit" :loading="false" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                <flux:icon.loading x-show="submitting" x-cloak class="size-4" />
                Cadastrar campus
            </flux:button>
            <flux:button variant="ghost" :href="route('campuses.index')" wire:navigate>Cancelar</flux:button>
        </div>
    </form>
</div>
