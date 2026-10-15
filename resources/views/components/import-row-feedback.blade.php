@props(['row'])

<flux:badge :color="match ($row['status']) { 'error' => 'red', 'imported' => 'green', 'ignored' => 'zinc', 'decision' => 'amber', default => 'blue' }">
    {{ match ($row['status']) { 'ready' => 'Válida', 'decision' => 'Decisão necessária', 'pending' => 'Pendente', 'imported' => 'Importada', 'ignored' => 'Ignorada', default => 'Com erro' } }}
</flux:badge>
@if ($row['reason'])
    <flux:text size="sm" class="mt-2">{{ $row['reason'] }}</flux:text>
@endif
{{ $slot }}
@foreach ($row['errors'] as $field => $messages)
    @foreach ($messages as $messageIndex => $message)
        <flux:text wire:key="import-error-{{ $row['position'] }}-{{ $field }}-{{ $messageIndex }}" size="sm" class="mt-2 text-red-600 dark:text-red-400">{{ $field }}: {{ $message }}</flux:text>
    @endforeach
@endforeach
