@props(['separator' => ';'])

<form wire:submit="analyze" {{ $attributes->class(['space-y-5']) }}>
    <flux:heading level="2">Enviar arquivo</flux:heading>
    <flux:input type="file" wire:model="csv" accept=".csv,text/csv,text/plain" label="Arquivo CSV" />
    <x-select wire:model="separator" label="Separador" :value="$separator" :options="[';' => 'Ponto e vírgula (;)', ',' => 'Vírgula (,)']" />
    <flux:error name="separator" />
    <div class="flex flex-wrap gap-3">
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="csv,analyze">Validar e mostrar prévia</flux:button>
        <flux:button icon="arrow-down-tray" type="button" variant="outline" wire:click="downloadTemplate">Baixar modelo CSV</flux:button>
    </div>
    <flux:text size="sm" wire:loading wire:target="csv,analyze" role="status">Lendo e validando arquivo…</flux:text>
</form>
