<?php

use App\Models\User;
use App\Models\Affiliation;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cadastrar vínculo')] class extends Component
{
    public function boot(): void
    {
        Gate::authorize('create', User::class);
    }

    #[Locked]
    public int $userId;

    public function mount(User $user): void
    {
        $this->userId = $user->id;
    }

    #[Computed]
    public function account(): User
    {
        return User::findOrFail($this->userId);
    }
}; ?>

<div class="w-full space-y-8">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('users.index')" wire:navigate>Usuários</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Cadastrar vínculo</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <form method="POST" action="{{ route('users.affiliations.store', $this->account) }}" class="space-y-8" novalidate x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false">
        @csrf

        <div class="flex flex-wrap items-start justify-between gap-4">
            <flux:heading size="xl" level="1">Cadastrar vínculo</flux:heading>
            <div class="flex items-center justify-end gap-3">
                <flux:button variant="outline" :href="route('users.show', $this->account)" wire:navigate>Cancelar</flux:button>
                <flux:button variant="primary" type="submit" :loading="false" x-bind:disabled="submitting">Salvar</flux:button>
            </div>
        </div>
        <livewire:users.form-fields :user="$this->account" />
    </form>
</div>
