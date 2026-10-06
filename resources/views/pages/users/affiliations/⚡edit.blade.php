<?php

use App\Models\User;
use App\Models\Affiliation;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Editar vínculo')] class extends Component
{
    public function boot(): void
    {
        Gate::authorize('create', User::class);
    }

    #[Locked]
    public int $userId;

    #[Locked]
    public int $affiliationId;

    public function mount(User $user, Affiliation $affiliation): void
    {
        $this->userId = $user->id;
        abort_unless($affiliation->user_id === $user->id, 404);
        $this->affiliationId = $affiliation->id;
    }

    #[Computed]
    public function account(): User
    {
        return User::findOrFail($this->userId);
    }

    #[Computed]
    public function affiliation(): Affiliation
    {
        $affiliation = $this->account->affiliations()->with('campus')->findOrFail($this->affiliationId);
        Gate::authorize('update', $affiliation);

        return $affiliation;
    }
}; ?>

<div class="w-full space-y-8">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('users.index')" wire:navigate>Usuários</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Editar vínculo</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <form method="POST" action="{{ route('users.affiliations.update', [$this->account, $this->affiliation]) }}" class="space-y-8" novalidate x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false">
        @csrf
        @method('PUT')
        <div class="flex flex-wrap items-start justify-between gap-4">
            <flux:heading size="xl" level="1">Editar vínculo</flux:heading>
            <div class="flex items-center justify-end gap-3">
                <flux:button variant="outline" :href="route('users.show', $this->account)" wire:navigate>Cancelar</flux:button>
                <flux:button variant="primary" type="submit" :loading="false" x-bind:disabled="submitting">Salvar</flux:button>
            </div>
        </div>
        <livewire:users.form-fields :user="$this->account" :affiliation="$this->affiliation" />
    </form>
</div>
