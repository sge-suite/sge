<?php

use App\Concerns\ProfileValidationRules;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component {
    use ProfileValidationRules;

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


    /**
     * Update the authenticated account's login email.
     */
    public function updateEmail(): void
    {
        $user = Auth::user();
        $this->email = mb_strtolower(trim($this->email));

        $validated = $this->validate([
            'email' => $this->emailRules($user->id),
        ]);

        $user->update(['email' => $validated['email']]);
        $this->email = $user->email;

        Flux::toast(variant: 'success', text: 'E-mail da conta atualizado.');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">Configurações do Perfil</flux:heading>

    <x-pages::settings.layout heading="Perfil" subheading="Informações do seu perfil">
        <div class="my-6 w-full space-y-6">
            <flux:input wire:model="name" label="Nome" type="text" readonly disabled />

            <form method="POST" wire:submit="updateEmail" class="space-y-4">
                <flux:input
                    wire:model="email"
                    label="E-mail da conta"
                    type="email"
                    required
                    autocomplete="email"
                />
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    Usado para entrar e recuperar sua senha. A alteração não modifica o e-mail dos seus vínculos.
                </p>
                <flux:error name="email" />

                <flux:button variant="primary" type="submit" data-test="update-account-email-button">
                    Salvar e-mail
                </flux:button>
            </form>
        </div>
    </x-pages::settings.layout>
</section>
