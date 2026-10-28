<?php

use App\Models\GrantingParty;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Editar parte concedente')] class extends Component
{
    #[Locked]
    public int $grantingPartyId;

    public function boot(): void
    {
        Gate::authorize('viewAny', GrantingParty::class);
        if (isset($this->grantingPartyId)) {
            Gate::authorize('update', GrantingParty::findOrFail($this->grantingPartyId));
        }
    }

    public function mount(GrantingParty $grantingParty): void
    {
        Gate::authorize('update', $grantingParty);
        $this->grantingPartyId = $grantingParty->id;
    }

    #[Computed]
    public function grantingParty(): GrantingParty
    {
        $grantingParty = GrantingParty::findOrFail($this->grantingPartyId);
        Gate::authorize('update', $grantingParty);

        return $grantingParty;
    }
}; ?>

<div class="w-full space-y-8">
    @php($grantingParty = $this->grantingParty)
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('granting-parties.index')" wire:navigate>Partes concedentes</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('granting-parties.show', $grantingParty)" wire:navigate>{{ $grantingParty->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Editar</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <form data-unsaved-changes="native-editor" data-unsaved-initial-dirty="{{ $errors->any() ? 'true' : 'false' }}" method="POST" action="{{ route('granting-parties.update', $grantingParty) }}" class="space-y-8" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false" novalidate>
        @csrf
        @method('PUT')
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">Editar parte concedente</flux:heading>
                <flux:text class="mt-2">Atualize os dados de {{ $grantingParty->name }}.</flux:text>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <flux:button variant="outline" :href="route('granting-parties.show', $grantingParty)" wire:navigate>Cancelar</flux:button>
                <flux:button variant="primary" type="submit" :loading="false" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                    <flux:icon.loading x-show="submitting" x-cloak class="size-4" />Salvar alterações
                </flux:button>
            </div>
        </div>
        <livewire:granting-parties.form-fields :grantingParty="$grantingParty" />
    </form>
</div>
