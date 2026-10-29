@props(['retry'])

<div {{ $attributes->class('space-y-2') }} role="alert" wire:loading.remove wire:target="{{ $retry }}">
    <flux:callout variant="warning">
        <flux:callout.heading>Não foi possível buscar</flux:callout.heading>
        <flux:callout.text>A busca está indisponível. Seus filtros e dados preenchidos foram mantidos.</flux:callout.text>
        <x-slot name="actions">
            <flux:button type="button" size="sm" variant="outline" wire:click="{{ $retry }}" wire:loading.attr="disabled">Tentar novamente</flux:button>
        </x-slot>
    </flux:callout>
</div>
<span wire:loading wire:target="{{ $retry }}" role="status" class="text-sm text-zinc-500 dark:text-zinc-400">Buscando novamente…</span>
