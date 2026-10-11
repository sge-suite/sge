<?php

use App\Models\InternshipType;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cadastrar tipo de estágio')] class extends Component
{
    public function boot(): void
    {
        Gate::authorize('create', InternshipType::class);
    }
}; ?>

<div class="w-full space-y-8">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('internship-types.index')" wire:navigate>Tipos de estágio</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Cadastrar</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <form method="POST" action="{{ route('internship-types.store') }}" class="space-y-8" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false" novalidate>
        @csrf
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">Cadastrar tipo de estágio</flux:heading>
                <flux:text class="mt-2">Selecione um curso do campus do vínculo selecionado.</flux:text>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <flux:button variant="outline" :href="route('internship-types.index')" wire:navigate>Cancelar</flux:button>
                <flux:button variant="primary" type="submit" :loading="false" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                    <flux:icon.loading x-show="submitting" x-cloak class="size-4" />Cadastrar tipo de estágio
                </flux:button>
            </div>
        </div>
        <livewire:internship-types.form-fields />
    </form>
</div>
