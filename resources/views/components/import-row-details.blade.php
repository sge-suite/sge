@props(['row'])

@if ($row['original'] !== [])
    <details {{ $attributes->class(['mt-2 whitespace-normal']) }}>
        <summary class="cursor-pointer text-sm text-zinc-600 dark:text-zinc-300">Ver dados da linha</summary>
        <dl class="mt-2 grid gap-2 text-sm">
            @foreach ($row['original'] as $header => $value)
                <div wire:key="import-field-{{ $row['position'] }}-{{ $loop->index }}" class="max-w-lg break-words">
                    <dt class="font-medium">{{ $header }}</dt>
                    <dd>{{ $value === '' ? '—' : Illuminate\Support\Str::limit($value, 300) }}</dd>
                </div>
            @endforeach
        </dl>
    </details>
@endif
