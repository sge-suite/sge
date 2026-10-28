@props(['label', 'value', 'icon', 'description' => null, 'href' => null])

<flux:card {{ $attributes->class(['relative flex min-w-0 items-center justify-between gap-3 p-4', 'transition-colors hover:bg-zinc-50 focus-within:ring-2 focus-within:ring-brand dark:hover:bg-zinc-700' => $href !== null]) }}>
    <div class="min-w-0">
        <flux:text size="sm">
            @if ($href)
                <a href="{{ $href }}" wire:navigate class="focus-visible:outline-none">{{ $label }}<span aria-hidden="true" class="absolute inset-0 rounded-xl"></span></a>
            @else
                {{ $label }}
            @endif
        </flux:text>
        <flux:heading size="xl" class="tabular-nums">{{ $value }}</flux:heading>
        @if ($description)
            <flux:text size="sm" class="mt-1">{{ $description }}</flux:text>
        @endif
    </div>
    <flux:avatar :icon="$icon" icon-variant="outline" size="lg" class="shrink-0 bg-brand/10 text-brand [&>svg]:opacity-100" />
</flux:card>
