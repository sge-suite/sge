@props(['activities'])

<flux:table :paginate="$activities">
    <flux:table.columns>
        <flux:table.column>Registro afetado</flux:table.column>
        <flux:table.column>Ação</flux:table.column>
        <flux:table.column>Realizada por</flux:table.column>
        <flux:table.column>Quando</flux:table.column>
    </flux:table.columns>
    <flux:table.rows>
        @foreach ($activities as $activity)
            <flux:table.row
                :key="$activity['id']"
                class="group cursor-pointer transition-colors hover:bg-zinc-100 focus-within:bg-zinc-100 focus:bg-zinc-100 dark:hover:bg-zinc-700 dark:focus-within:bg-zinc-700 dark:focus:bg-zinc-700"
                tabindex="0"
                :aria-label="'Abrir auditoria: '.$activity['subject']"
                x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-audit-link]').click()"
                x-on:keydown.enter.prevent="$el.querySelector('[data-audit-link]').click()"
                x-on:keydown.space.prevent="$el.querySelector('[data-audit-link]').click()"
            >
                <flux:table.cell class="whitespace-normal">
                    <a data-audit-link tabindex="-1" href="{{ route('audit.show', $activity['id']) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">{{ $activity['subject'] }}</a>
                </flux:table.cell>
                <flux:table.cell><flux:badge size="sm" color="zinc">{{ $activity['event'] }}</flux:badge></flux:table.cell>
                <flux:table.cell class="whitespace-normal">{{ $activity['actor'] }}</flux:table.cell>
                <flux:table.cell>{{ $activity['occurred_at'] }}</flux:table.cell>
            </flux:table.row>
        @endforeach
    </flux:table.rows>
</flux:table>
