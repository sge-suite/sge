<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Editar dados de acesso')] class extends Component
{
    #[Locked]
    public int $userId;

    public function boot(): void
    {
        Gate::authorize('viewAny', User::class);
    }

    public function mount(User $user): void
    {
        $this->userId = $user->id;
    }

    #[Computed]
    public function account(): User
    {
        $user = User::findOrFail($this->userId);
        Gate::authorize('update', $user);

        return $user;
    }
}; ?>

<div class="w-full space-y-8">
    @php($account = $this->account)
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('users.index')" wire:navigate>Usuários</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('users.show', $account)" wire:navigate>{{ $account->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Dados de acesso</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <form method="POST" action="{{ route('users.update', $account) }}" class="space-y-8" novalidate x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false">
        @csrf
        @method('PUT')
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">Editar dados de acesso</flux:heading>
                <flux:text class="mt-2">Atualize a identificação e o e-mail usado para entrar no sistema.</flux:text>
            </div>
            <div class="flex items-center justify-end gap-3">
                <flux:button :href="route('users.show', $account)" wire:navigate>Cancelar</flux:button>
                <flux:button type="submit" variant="primary" x-bind:disabled="submitting">Salvar</flux:button>
            </div>
        </div>
        <div class="grid items-start gap-8 lg:grid-cols-2">
            <section class="space-y-5">
                <flux:heading size="lg" level="2">Identificação</flux:heading>
                <flux:input name="name" label="Nome completo" :value="is_string(old('name', $account->name)) ? old('name', $account->name) : ''" maxlength="255" error:name="name" />
                <flux:input name="cpf" label="CPF" :value="is_string(old('cpf', $account->cpf)) ? old('cpf', $account->cpf) : ''" maxlength="14" error:name="cpf" />
                <flux:text size="sm">Confira o CPF antes de salvar a correção. Ele deve ser válido e não pertencer a outra conta.</flux:text>
            </section>
            <section class="space-y-5">
                <flux:heading size="lg" level="2">Login</flux:heading>
                <flux:input name="email" label="E-mail de login" type="email" :value="is_string(old('email', $account->email)) ? old('email', $account->email) : ''" maxlength="255" error:name="email" />
                <flux:text size="sm">Ao alterar o e-mail, enviaremos avisos aos endereços antigo e novo. Os e-mails dos vínculos e a senha continuam iguais.</flux:text>
            </section>
        </div>
    </form>
</div>
