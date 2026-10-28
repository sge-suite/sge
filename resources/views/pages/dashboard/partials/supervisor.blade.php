<div class="w-full space-y-8">
    <x-dashboard-header description="Supervisor · Preencha as avaliações solicitadas e acompanhe a análise do Setor de Estágios.">
        <x-slot:actions>
            <flux:button variant="primary" icon="clipboard-document-list" :href="route('supervisor-evaluations.index')" wire:navigate>Minhas avaliações</flux:button>
        </x-slot:actions>
    </x-dashboard-header>

    <section aria-label="Resumo das minhas avaliações" class="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-dashboard-metric label="Devolvidas para correção" :value="$overview['groups']['returned']['count']" icon="pencil-square" description="Confira a orientação do Setor" />
        <x-dashboard-metric label="Para preencher" :value="$overview['groups']['draft']['count']" icon="clipboard-document-list" description="Avaliações ainda não enviadas" />
        <x-dashboard-metric label="Aguardando o Setor" :value="$overview['groups']['submitted']['count']" icon="clock" description="Enviadas para análise" />
        <x-dashboard-metric label="Aceitas pelo Setor" :value="$overview['approved']" icon="check-circle" description="Avaliações aprovadas" />
    </section>

    @foreach ([
        'returned' => ['title' => 'Devolvidas para correção', 'description' => 'Confira a orientação do Setor antes de reenviar.'],
        'draft' => ['title' => 'Para preencher', 'description' => 'Avaliações solicitadas pelo Setor que ainda não foram enviadas.'],
        'submitted' => ['title' => 'Enviadas ao Setor', 'description' => 'Consulte suas respostas enquanto aguarda a análise.'],
    ] as $status => $section)
        @if ($overview['groups'][$status]['count'] > 0)
            <section wire:key="supervisor-dashboard-{{ $status }}" aria-labelledby="supervisor-{{ $status }}" class="min-w-0 space-y-4">
                <div>
                    <flux:heading id="supervisor-{{ $status }}" size="lg" level="2">{{ $section['title'] }}</flux:heading>
                    <flux:text class="mt-1" size="sm">{{ $section['description'] }} Até cinco avaliações, começando pelas atualizadas há mais tempo.</flux:text>
                </div>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Avaliação / Discente</flux:table.column>
                        <flux:table.column class="hidden md:table-cell">Curso / Concedente</flux:table.column>
                        <flux:table.column class="hidden lg:table-cell">Última atualização</flux:table.column>
                        <flux:table.column><span class="sr-only">Ação</span></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($overview['groups'][$status]['evaluations'] as $evaluation)
                            <flux:table.row :key="'dashboard-evaluation-'.$evaluation->id" class="group cursor-pointer transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-dashboard-evaluation-link]').click()">
                                <flux:table.cell class="whitespace-normal">
                                    <a data-dashboard-evaluation-link href="{{ route('supervisor-evaluations.show', $evaluation) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">Nº {{ $evaluation->id }} · {{ $evaluation->internship->student_snapshot['name'] ?? 'Discente' }}</a>
                                    <flux:text size="sm">Estágio nº {{ $evaluation->internship_id }} · Matrícula: {{ $evaluation->internship->student_snapshot['registration_number'] ?? 'Não informada' }}</flux:text>
                                    <flux:text size="sm">{{ $evaluation->internship->internship_type_snapshot['name'] ?? 'Tipo não informado' }}</flux:text>
                                    <div class="space-y-1 md:hidden">
                                        <flux:text size="sm">Curso: {{ $evaluation->internship->course->name }}</flux:text>
                                        <flux:text size="sm">Concedente: {{ $evaluation->internship->granting_party_snapshot['name'] ?? 'Não informada' }}</flux:text>
                                    </div>
                                    <flux:text size="sm" class="lg:hidden">Atualizada: {{ formatDateTime($evaluation->updated_at) }}</flux:text>
                                </flux:table.cell>
                                <flux:table.cell class="hidden whitespace-normal md:table-cell">
                                    {{ $evaluation->internship->course->name }}
                                    <flux:text size="sm">{{ $evaluation->internship->granting_party_snapshot['name'] ?? 'Não informada' }}</flux:text>
                                </flux:table.cell>
                                <flux:table.cell class="hidden lg:table-cell">{{ formatDateTime($evaluation->updated_at) }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    @can('update', $evaluation)
                                        <flux:button variant="outline" size="sm" :href="route('supervisor-evaluations.show', $evaluation)" wire:navigate>Continuar avaliação</flux:button>
                                    @else
                                        <flux:button variant="outline" size="sm" :href="route('supervisor-evaluations.show', $evaluation)" wire:navigate>Consultar</flux:button>
                                    @endcan
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </section>
        @endif
    @endforeach

    @if ($overview['groups']['returned']['count'] + $overview['groups']['draft']['count'] + $overview['groups']['submitted']['count'] === 0)
        <flux:callout icon="check-circle" :heading="$overview['total'] === 0 ? 'Nenhuma avaliação solicitada' : 'Nenhuma avaliação pendente'">
            {{ $overview['total'] === 0 ? 'As avaliações solicitadas pelo Setor aparecerão aqui.' : 'Você pode consultar as avaliações aprovadas ou canceladas em Minhas avaliações.' }}
        </flux:callout>
    @endif
</div>
