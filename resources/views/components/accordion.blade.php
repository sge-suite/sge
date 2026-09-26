@props(['title', 'open' => false])

<div
    {{ $attributes->class('t-acc rounded-lg border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900/50') }}
    data-open="{{ $open ? 'true' : 'false' }}"
    x-data="{ open: @js($open) }"
    x-bind:data-open="open ? 'true' : 'false'"
>
    <button
        type="button"
        class="t-acc-head flex w-full items-center justify-between gap-3 rounded-lg px-4 py-3 text-start text-sm font-medium text-zinc-700 hover:text-zinc-950 dark:text-zinc-200 dark:hover:text-white"
        aria-expanded="{{ $open ? 'true' : 'false' }}"
        x-bind:aria-expanded="open ? 'true' : 'false'"
        x-on:click="open = ! open"
    >
        <span>{{ $title }}</span>
        <span class="t-acc-chevron text-zinc-500 dark:text-zinc-400" aria-hidden="true">
            <svg viewBox="0 0 16 16" class="size-4 fill-none stroke-current stroke-2">
                <path d="M4 6.5L8 10.5L12 6.5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </span>
    </button>

    <div class="t-acc-panel" aria-hidden="{{ $open ? 'false' : 'true' }}" x-bind:aria-hidden="open ? 'false' : 'true'">
        <div class="t-acc-panel-inner">
            <div class="px-4 pb-4">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
