@props(['description'])

<header class="flex min-w-0 flex-wrap items-start justify-between gap-4">
    <div class="min-w-0 flex-1 basis-72">
        <flux:heading level="1" size="xl">Painel</flux:heading>
        <flux:text class="mt-2 wrap-break-word">{{ $description }}</flux:text>
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-3">{{ $actions }}</div>
    @endisset
</header>
