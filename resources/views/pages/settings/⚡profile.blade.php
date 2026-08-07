<?php

use App\Concerns\ProfileValidationRules;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
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


}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">Configurações do Perfil</flux:heading>

    <x-pages::settings.layout heading="Perfil" subheading="Informações do seu perfil">
        <div class="my-6 w-full space-y-6">
            <flux:input wire:model="name" label="Nome" type="text" readonly disabled />

            <div>
                <flux:input wire:model="email" label="E-mail" type="email" readonly disabled />
            </div>
        </div>
    </x-pages::settings.layout>
</section>
