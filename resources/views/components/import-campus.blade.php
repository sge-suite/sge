@props(['name'])

<div {{ $attributes->class(['flex flex-col gap-2 border-b border-zinc-200 pb-5 sm:flex-row sm:items-start sm:gap-4 dark:border-zinc-700']) }}>
    <flux:text size="sm" class="shrink-0 sm:pt-0.5">Campus de destino</flux:text>
    <div class="min-w-0 space-y-1">
        <flux:text class="break-words font-medium text-zinc-900 dark:text-zinc-100">{{ $name }}</flux:text>
        <flux:text size="sm">{{ $slot->isEmpty() ? 'A importação será feita somente neste campus.' : $slot }}</flux:text>
    </div>
</div>
