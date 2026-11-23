@props(['internships', 'signatures' => false])

<flux:table>
    <flux:table.columns>
        <flux:table.column>Estágio / Discente</flux:table.column>
        <flux:table.column class="hidden md:table-cell">{{ $signatures ? 'Atualização documental' : 'Início previsto' }}</flux:table.column>
        <flux:table.column>Situação do estágio</flux:table.column>
    </flux:table.columns>
    <flux:table.rows>
        @foreach ($internships as $internship)
            <flux:table.row :key="($signatures ? 'signature-' : 'delayed-').$internship->id" class="group cursor-pointer transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-queue-internship-link]').click()">
                <flux:table.cell class="whitespace-normal">
                    <a data-queue-internship-link href="{{ route('internships.show', $internship).($signatures ? '#internship-documents' : '') }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">Nº {{ $internship->id }} · {{ $internship->student_snapshot['name'] ?? 'Discente' }}</a>
                    <flux:text size="sm">{{ $internship->internship_type_snapshot['name'] ?? 'Tipo não informado' }}</flux:text>
                    @if ($signatures)<flux:text size="sm">Documento aguardando assinatura</flux:text>@endif
                    <flux:text size="sm" class="md:hidden">{{ $signatures ? 'Atualização documental' : 'Início previsto' }}: {{ formatShort($signatures ? $internship->generated_documents_min_updated_at : $internship->planned_start_date) }}</flux:text>
                </flux:table.cell>
                <flux:table.cell class="hidden md:table-cell">{{ formatShort($signatures ? $internship->generated_documents_min_updated_at : $internship->planned_start_date) }}</flux:table.cell>
                <flux:table.cell class="whitespace-normal"><flux:badge size="sm" color="amber">{{ $internship->status->label() }}</flux:badge></flux:table.cell>
            </flux:table.row>
        @endforeach
    </flux:table.rows>
</flux:table>
