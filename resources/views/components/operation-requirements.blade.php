@props(['items', 'title', 'id', 'attempted' => false])

@php
    $pendingItems = collect($items)->filter(fn (array $item): bool => ! $item['met']);
    $blocked = $attempted && $pendingItems->isNotEmpty();
    $visibleItems = $attempted ? $pendingItems : $items;
@endphp

<section {{ $attributes->class(['space-y-3 rounded-xl border p-4', 'border-amber-400 bg-amber-50 dark:border-amber-700 dark:bg-amber-950/30' => $blocked, 'border-zinc-200 dark:border-zinc-700' => ! $blocked]) }} aria-labelledby="{{ $id }}" x-data
    x-on:click="const link = $event.target.closest('a'); if (link?.getAttribute('href')?.startsWith('#')) { const target = document.getElementById(link.hash.slice(1)); if (target) { $event.preventDefault(); if (target.tabIndex < 0 && !target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1'); target.focus({ preventScroll: true }); target.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'start' }); } }">
    <div aria-live="polite">
        <flux:heading :id="$id" tabindex="-1" class="scroll-mt-6" level="3">{{ $blocked ? 'Ainda não é possível aceitar esta solicitação.' : $title }}</flux:heading>
        @if ($blocked)
            <flux:text>Resolva as pendências abaixo para continuar.</flux:text>
        @elseif ($attempted)
            <flux:text>As pendências desta lista foram resolvidas. Clique em “Aceitar e formalizar” para conferir os dados atuais e continuar.</flux:text>
        @endif
    </div>
    <flux:text size="sm">Conferência preliminar dos requisitos abaixo. A operação revalida os dados no servidor; atender esta lista não substitui a análise completa.</flux:text>
    <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
        @foreach ($visibleItems as $item)
            <li wire:key="{{ $id }}-{{ $item['label'] }}" class="flex items-start gap-3 py-3">
                @if ($item['met'])<flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-green-600 dark:text-green-400" />@else<flux:icon.exclamation-circle class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />@endif
                <div class="min-w-0 flex-1 space-y-1">
                    <p class="text-sm font-medium wrap-anywhere text-zinc-900 dark:text-white">{{ $item['label'] }} <span class="font-normal text-zinc-500 dark:text-zinc-400">· {{ $item['met'] ? 'Conferido' : 'Pendente' }}</span></p>
                    <flux:text size="sm" class="wrap-anywhere">{{ $item['description'] }}</flux:text>
                    @if (! $item['met'])<a href="{{ $item['href'] }}" class="inline-block text-sm font-medium text-brand underline underline-offset-4">{{ $item['action'] }}</a>@endif
                </div>
            </li>
        @endforeach
    </ul>
</section>
