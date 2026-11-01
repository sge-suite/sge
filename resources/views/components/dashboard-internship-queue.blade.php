@props(['internships', 'signatures' => false])

<flux:table>
    <flux:table.columns>
        <flux:table.column>Estágio / Discente</flux:table.column>
        <flux:table.column class="hidden md:table-cell">{{ $signatures ? 'Atualização documental' : 'Início previsto' }}</flux:table.column>
        <flux:table.column>Situação do estágio</flux:table.column>
        <flux:table.column><span class="sr-only">Ação</span></flux:table.column>
    </flux:table.columns>
    <flux:table.rows>
        @foreach ($internships as $internship)
            <flux:table.row :key="($signatures ? 'signature-' : 'delayed-').$internship->id">
                <flux:table.cell class="whitespace-normal">
                    <span class="font-medium">Nº {{ $internship->id }} · {{ $internship->student_snapshot['name'] ?? 'Discente' }}</span>
                    <flux:text size="sm">{{ $internship->internship_type_snapshot['name'] ?? 'Tipo não informado' }}</flux:text>
                    @if ($signatures)<flux:text size="sm">Documento aguardando assinatura</flux:text>@endif
                    <flux:text size="sm" class="md:hidden">{{ $signatures ? 'Atualização documental' : 'Início previsto' }}: {{ formatShort($signatures ? $internship->generated_documents_min_updated_at : $internship->planned_start_date) }}</flux:text>
                </flux:table.cell>
                <flux:table.cell class="hidden md:table-cell">{{ formatShort($signatures ? $internship->generated_documents_min_updated_at : $internship->planned_start_date) }}</flux:table.cell>
                <flux:table.cell class="whitespace-normal"><flux:badge size="sm" color="amber">{{ $internship->status->label() }}</flux:badge></flux:table.cell>
                <flux:table.cell><flux:button size="sm" variant="outline" :href="route('internships.show', $internship).($signatures ? '#internship-documents' : '')" wire:navigate :aria-label="($signatures ? 'Ver documentos do estágio nº ' : 'Consultar estágio nº ').$internship->id">{{ $signatures ? 'Ver documentos' : 'Consultar estágio' }}</flux:button></flux:table.cell>
            </flux:table.row>
        @endforeach
    </flux:table.rows>
</flux:table>
