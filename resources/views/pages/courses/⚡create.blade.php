<?php

use App\Models\Course;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cadastrar curso')] class extends Component
{
    public function boot(): void
    {
        Gate::authorize('create', Course::class);
    }
}; ?>

<div class="w-full space-y-8">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('courses.index')" wire:navigate>Cursos</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Cadastrar</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <form method="POST" action="{{ route('courses.store') }}" class="space-y-8" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false" novalidate>
        @csrf
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">Cadastrar curso</flux:heading>
                <flux:text class="mt-2">O curso será cadastrado no campus do vínculo selecionado.</flux:text>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <flux:button variant="outline" :href="route('courses.index')" wire:navigate>Cancelar</flux:button>
                <flux:button variant="primary" type="submit" :loading="false" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                    <flux:icon.loading x-show="submitting" x-cloak class="size-4" />Cadastrar curso
                </flux:button>
            </div>
        </div>
        <livewire:courses.form-fields />
    </form>
</div>
