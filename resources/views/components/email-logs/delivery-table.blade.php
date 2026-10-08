@props(['deliveries'])

<flux:table :paginate="$deliveries">
    <flux:table.columns>
        <flux:table.column>Assunto</flux:table.column><flux:table.column>Destinatário</flux:table.column><flux:table.column>Finalidade</flux:table.column><flux:table.column>Situação</flux:table.column><flux:table.column>Quando</flux:table.column>
    </flux:table.columns>
    <flux:table.rows>
        @foreach ($deliveries as $delivery)
            <flux:table.row :key="$delivery->id" class="group cursor-pointer transition-colors hover:bg-zinc-100 focus-within:bg-zinc-100 focus:bg-zinc-100 dark:hover:bg-zinc-700 dark:focus-within:bg-zinc-700 dark:focus:bg-zinc-700"
                tabindex="0" :aria-label="'Abrir envio para '.$delivery->recipient_email"
                x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-email-log-link]').click()"
                x-on:keydown.enter.prevent="$el.querySelector('[data-email-log-link]').click()"
                x-on:keydown.space.prevent="$el.querySelector('[data-email-log-link]').click()">
                <flux:table.cell class="whitespace-normal">
                    <a data-email-log-link tabindex="-1" href="{{ route('email-logs.show', $delivery) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">{{ $delivery->emailMessage?->subject ?? $delivery->purpose->label() }}</a>
                </flux:table.cell>
                <flux:table.cell class="whitespace-normal break-all">{{ $delivery->recipient_email }}</flux:table.cell>
                <flux:table.cell>{{ $delivery->purpose->label() }}</flux:table.cell>
                <flux:table.cell><flux:badge size="sm" :color="match ($delivery->status->value) { 'sent' => 'green', 'failed' => 'red', default => 'zinc' }">{{ $delivery->status->label() }}</flux:badge></flux:table.cell>
                <flux:table.cell>{{ formatDateTime($delivery->created_at) }}</flux:table.cell>
            </flux:table.row>
        @endforeach
    </flux:table.rows>
</flux:table>
