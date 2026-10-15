@props(['status', 'errors' => 0, 'confirmDisabled' => false])

<div {{ $attributes->class(['flex flex-wrap items-center gap-3']) }}>
    @if ($errors > 0)
        <flux:button icon="arrow-down-tray" variant="outline" wire:click="downloadErrors" x-bind:disabled="processing">Baixar CSV de erros</flux:button>
    @endif
    @if ($status === 'preview')
        <flux:button icon="arrow-up-tray" variant="primary" wire:click="confirm" wire:loading.attr="disabled" :disabled="$confirmDisabled">
            {{ $slot->isEmpty() ? 'Confirmar importação' : $slot }}
        </flux:button>
    @elseif ($status === 'running')
        <flux:button variant="primary" x-on:click="start()" x-bind:disabled="processing">Continuar processamento</flux:button>
        <flux:button variant="outline" x-on:click="pause()" x-show="processing">Pausar após este lote</flux:button>
    @endif
    <flux:button variant="outline" wire:click="startOver" x-bind:disabled="processing">Enviar outro arquivo</flux:button>
</div>
