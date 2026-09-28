<?php

use App\Models\Campus;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Editar campus')] class extends Component
{
    #[Locked]
    public int $campusId;

    public function boot(): void
    {
        Gate::authorize('viewAdministration', Campus::class);
    }

    public function mount(Campus $campus): void
    {
        $this->campusId = $campus->id;
    }

    #[Computed]
    public function campus(): Campus
    {
        $campus = Campus::with('address.city')->findOrFail($this->campusId);
        Gate::authorize('update', $campus);

        return $campus;
    }
}; ?>

<div class="mx-auto w-full max-w-3xl space-y-8">
    @php($campus = $this->campus)
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('campuses.index')" wire:navigate>Campi</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('campuses.show', $campus)" wire:navigate>{{ $campus->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Editar</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <div>
        <flux:heading size="xl" level="1">Editar campus</flux:heading>
        <flux:text class="mt-2">Atualize os dados de {{ $campus->name }}.</flux:text>
    </div>
    <form method="POST" action="{{ route('campuses.update', $campus) }}" class="space-y-8" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false">
        @csrf
        @method('PUT')
        <livewire:campuses.form-fields :campus="$campus" />
        <div class="flex flex-wrap items-center gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <flux:button variant="primary" type="submit" :loading="false" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                <flux:icon.loading x-show="submitting" x-cloak class="size-4" />
                Salvar alterações
            </flux:button>
            <flux:button variant="ghost" :href="route('campuses.show', $campus)" wire:navigate>Cancelar</flux:button>
        </div>
    </form>
</div>
