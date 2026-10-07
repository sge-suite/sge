@props([
    'label' => 'Atualizando lista…',
    'target' => null,
    'dimWhileLoading' => false,
])

<div class="relative">
    <span
        wire:loading
        @if ($target !== null) wire:target="{{ $target }}" @endif
        class="pointer-events-none absolute -top-7 end-0 text-sm text-zinc-500 dark:text-zinc-400"
        role="status"
    >{{ $label }}</span>
    <div
        @if ($dimWhileLoading) wire:loading.class="opacity-60" @endif
        @if ($target !== null) wire:target="{{ $target }}" @endif
    >
        {{ $slot }}
    </div>
</div>
