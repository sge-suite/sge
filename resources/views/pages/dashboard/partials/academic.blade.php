<div class="w-full space-y-8">
    <x-dashboard-header :description="$overview['role'].' · '.$overview['campus'].' · '.$overview['description']">
        <x-slot:actions>
            <flux:button variant="primary" icon="briefcase" :href="route('internships.index')" wire:navigate>Estágios</flux:button>
        </x-slot:actions>
    </x-dashboard-header>
    <section aria-label="Resumo dos estágios" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => 'Estágios', 'value' => $overview['total'], 'icon' => 'briefcase'],
            ['label' => 'Em andamento', 'value' => $overview['inProgress'], 'icon' => 'play-circle'],
            ['label' => 'Em pausa', 'value' => $overview['paused'], 'icon' => 'pause-circle'],
            ['label' => 'Concluídos', 'value' => $overview['completed'], 'icon' => 'check-circle'],
        ] as $metric)
            <x-dashboard-metric wire:key="academic-metric-{{ $loop->index }}" :label="$metric['label']" :value="$metric['value']" :icon="$metric['icon']" />
        @endforeach
    </section>
    @if ($overview['pendingGradesCount'] > 0)
        <section class="space-y-4" aria-labelledby="academic-grades-heading">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <flux:heading id="academic-grades-heading" level="2" size="lg">Notas acadêmicas pendentes ({{ $overview['pendingGradesCount'] }})</flux:heading>
                    <flux:text size="sm">Até 5 estágios, pelo término previsto mais antigo.</flux:text>
                </div>
                <flux:button variant="ghost" icon="arrow-right" :href="route('internships.index')" wire:navigate>Ver todos os estágios</flux:button>
            </div>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Estágio / Discente</flux:table.column>
                    <flux:table.column>Notas faltantes</flux:table.column>
                    <flux:table.column><span class="sr-only">Ação</span></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($overview['pendingGrades'] as $internship)
                        <flux:table.row :key="'pending-grades-'.$internship->id">
                            <flux:table.cell class="whitespace-normal">
                                <span class="font-medium">Nº {{ $internship->id }} · {{ $internship->student_snapshot['name'] ?? 'Discente' }}</span>
                                <flux:text size="sm">{{ $internship->internship_type_snapshot['name'] ?? 'Tipo não informado' }}</flux:text>
                                <flux:text size="sm">Término previsto: {{ formatShort($internship->projected_end_date) }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-normal">
                                @if ($internship->report_grade === null && ($internship->internship_type_snapshot['rules']['report_weight'] ?? 0) > 0)<flux:text size="sm">Relatório</flux:text>@endif
                                @if ($internship->presentation_grade === null && ($internship->internship_type_snapshot['rules']['presentation_weight'] ?? 0) > 0)<flux:text size="sm">Apresentação</flux:text>@endif
                            </flux:table.cell>
                            <flux:table.cell><flux:button size="sm" variant="primary" :href="route('internships.show', $internship).'#grades-heading'" wire:navigate :aria-label="'Lançar notas do estágio nº '.$internship->id">Lançar notas</flux:button></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
            @if ($overview['pendingGradesCount'] > $overview['pendingGrades']->count())<flux:text size="sm">Há mais {{ $overview['pendingGradesCount'] - $overview['pendingGrades']->count() }} estágios na listagem completa.</flux:text>@endif
        </section>
    @endif
    <section class="space-y-4" aria-labelledby="academic-updates-heading">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading id="academic-updates-heading" level="2" size="lg">Últimas atualizações</flux:heading>
            <flux:button variant="outline" icon="briefcase" :href="route('internships.index')" wire:navigate>Ver todos os estágios</flux:button>
        </div>
        @if ($overview['internships']->isNotEmpty())
            <x-academic-internships-table :internships="$overview['internships']" :is-office="$overview['isOffice']" />
        @else
            <flux:callout icon="briefcase" heading="Nenhum estágio disponível">Os estágios vinculados ao seu acompanhamento aparecerão aqui.</flux:callout>
        @endif
    </section>
</div>
