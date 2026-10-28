<?php

use App\Models\InternshipType;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Editar tipo de estágio')] class extends Component
{
    #[Locked]
    public int $internshipTypeId;

    public function boot(): void
    {
        Gate::authorize('viewAny', InternshipType::class);
        if (isset($this->internshipTypeId)) {
            Gate::authorize('update', InternshipType::findOrFail($this->internshipTypeId));
        }
    }

    public function mount(InternshipType $internshipType): void
    {
        Gate::authorize('update', $internshipType);
        $this->internshipTypeId = $internshipType->id;
    }

    #[Computed]
    public function internshipType(): InternshipType
    {
        $internshipType = InternshipType::findOrFail($this->internshipTypeId);
        Gate::authorize('update', $internshipType);

        return $internshipType;
    }
}; ?>

<div class="w-full space-y-8">
    @php($internshipType = $this->internshipType)
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('internship-types.index')" wire:navigate>Tipos de estágio</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('internship-types.show', $internshipType)" wire:navigate>{{ $internshipType->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Editar</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <form data-unsaved-changes="native-editor" data-unsaved-initial-dirty="{{ $errors->any() ? 'true' : 'false' }}" method="POST" action="{{ route('internship-types.update', $internshipType) }}" class="space-y-8" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false" novalidate>
        @csrf
        @method('PUT')
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">Editar tipo de estágio</flux:heading>
                <flux:text class="mt-2">Atualize os dados de {{ $internshipType->name }}.</flux:text>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <flux:button variant="outline" :href="route('internship-types.show', $internshipType)" wire:navigate>Cancelar</flux:button>
                <flux:button variant="primary" type="submit" :loading="false" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                    <flux:icon.loading x-show="submitting" x-cloak class="size-4" />Salvar alterações
                </flux:button>
            </div>
        </div>
        <livewire:internship-types.form-fields :internshipType="$internshipType" />
    </form>
</div>
