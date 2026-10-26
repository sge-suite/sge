@props(['internships', 'paginated' => false])

<flux:table :paginate="$paginated ? $internships : null">
    <flux:table.columns>
        <flux:table.column>Estágio / Concedente</flux:table.column>
        <flux:table.column class="hidden md:table-cell">Orientação</flux:table.column>
        <flux:table.column>Situação</flux:table.column>
        <flux:table.column class="hidden lg:table-cell">Início / Término previsto</flux:table.column>
    </flux:table.columns>
    <flux:table.rows>
        @foreach ($internships as $internship)
            <flux:table.row :key="'student-internship-'.$internship->id" class="group cursor-pointer transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-student-internship-link]').click()">
                <flux:table.cell class="whitespace-normal">
                    <a data-student-internship-link href="{{ route('internships.show', $internship) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">Estágio nº {{ $internship->id }}</a>
                    <flux:text size="sm">{{ $internship->granting_party_snapshot['name'] ?? 'Concedente não informada' }}</flux:text>
                    <flux:text size="sm">{{ $internship->internship_type_snapshot['name'] ?? 'Tipo não informado' }}</flux:text>
                    <flux:text size="sm">Atualizado em {{ formatDateTime($internship->updated_at) }}</flux:text>
                    <div class="space-y-1 md:hidden"><flux:text size="sm">Orientação: {{ $internship->advisorAffiliation?->user?->name ?? 'Não informada' }}</flux:text>@if ($internship->co_advisor_name)<flux:text size="sm">Co-orientação: {{ $internship->co_advisor_name }}</flux:text>@endif</div>
                    <flux:text size="sm" class="lg:hidden">Início: {{ formatShort($internship->planned_start_date) }} · Término previsto: {{ formatShort($internship->projected_end_date) }}</flux:text>
                </flux:table.cell>
                <flux:table.cell class="hidden whitespace-normal md:table-cell">
                    {{ $internship->advisorAffiliation?->user?->name ?? 'Não informado' }}
                    @if ($internship->co_advisor_name)<flux:text size="sm">Co-orientador: {{ $internship->co_advisor_name }}</flux:text>@endif
                </flux:table.cell>
                <flux:table.cell><flux:badge size="sm" :color="match ($internship->status->value) { 'released', 'in_progress' => 'green', 'awaiting_signatures', 'paused' => 'amber', 'pending_correction' => 'orange', 'completed' => 'blue', 'cancelled' => 'red', default => 'zinc' }">{{ $internship->status->label() }}</flux:badge></flux:table.cell>
                <flux:table.cell class="hidden lg:table-cell">{{ formatShort($internship->planned_start_date) }}<flux:text size="sm">{{ formatShort($internship->projected_end_date) }}</flux:text></flux:table.cell>
            </flux:table.row>
        @endforeach
    </flux:table.rows>
</flux:table>
