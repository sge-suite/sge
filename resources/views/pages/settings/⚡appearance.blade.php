<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Appearance settings')] class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">Configurações de Aparência</flux:heading>

    <x-pages::settings.layout heading="Aparência" subheading="Atualize as configurações de aparência da sua conta">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun">Claro</flux:radio>
            <flux:radio value="dark" icon="moon">Escuro</flux:radio>
            <flux:radio value="system" icon="computer-desktop">Sistema</flux:radio>
        </flux:radio.group>
    </x-pages::settings.layout>
</section>
