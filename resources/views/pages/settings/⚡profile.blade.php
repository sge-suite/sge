<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component {
    public string $name = '';
    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">Configurações do Perfil</flux:heading>

    <x-pages::settings.layout heading="Perfil" subheading="Informações do seu perfil">
        <div class="my-6 w-full space-y-6">
            <flux:input wire:model="name" label="Nome" type="text" readonly disabled />

            <div class="space-y-2">
                <flux:input wire:model="email" label="E-mail da conta" type="email" readonly disabled />
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    Usado para entrar e recuperar sua senha. Para alterá-lo, acesse
                    <flux:link :href="route('security.edit')">Segurança</flux:link>.
                    O e-mail dos seus vínculos não é alterado.
                </p>
            </div>
        </div>
    </x-pages::settings.layout>
</section>
