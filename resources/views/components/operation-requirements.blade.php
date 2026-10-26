@props(['items', 'title', 'id'])

<section {{ $attributes->class('space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700') }} aria-labelledby="{{ $id }}">
    <flux:heading :id="$id" level="3">{{ $title }}</flux:heading>
    <flux:text size="sm">Conferência preliminar dos requisitos abaixo. A operação revalida os dados no servidor; atender esta lista não substitui a análise completa.</flux:text>
    <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
        @foreach ($items as $item)
            <li wire:key="{{ $id }}-{{ $loop->index }}" class="flex items-start gap-3 py-3">
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
