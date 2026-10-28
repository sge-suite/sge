<?php

use App\Models\GrantingParty;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cadastrar parte concedente')] class extends Component
{
    public function boot(): void
    {
        Gate::authorize('create', GrantingParty::class);
    }
}; ?>

<div class="w-full space-y-8">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('granting-parties.index')" wire:navigate>Partes concedentes</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Cadastrar</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <form data-unsaved-changes="native-editor" data-unsaved-initial-dirty="{{ $errors->any() ? 'true' : 'false' }}" method="POST" action="{{ route('granting-parties.store') }}" class="space-y-8" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false" novalidate>
        @csrf
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">Cadastrar parte concedente</flux:heading>
                <flux:text class="mt-2">A parte concedente será cadastrada no campus do vínculo selecionado.</flux:text>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <flux:button variant="outline" :href="route('granting-parties.index')" wire:navigate>Cancelar</flux:button>
                <flux:button variant="primary" type="submit" :loading="false" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                    <flux:icon.loading x-show="submitting" x-cloak class="size-4" />Cadastrar parte concedente
                </flux:button>
            </div>
        </div>
        <livewire:granting-parties.form-fields />
    </form>
</div>
