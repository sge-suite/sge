<div class="w-full space-y-8">
    <x-dashboard-header :description="$overview['campus'].' · Pendências e acompanhamento do Setor de Estágios.'">
        <x-slot:actions>
            <flux:button variant="primary" icon="clipboard-document-check" :href="route('internship-review.index')" wire:navigate>Solicitações</flux:button>
            <flux:button variant="outline" icon="briefcase" :href="route('internships.index')" wire:navigate>Estágios</flux:button>
            <flux:button variant="outline" icon="clipboard-document-list" :href="route('supervisor-evaluations.index')" wire:navigate>Avaliações</flux:button>
        </x-slot:actions>
    </x-dashboard-header>
    @if ($overview['readOnly'])
        <flux:callout icon="information-circle" heading="Campus somente para leitura">O campus está desativado. Os dados permanecem disponíveis para consulta.</flux:callout>
    @endif
    <section aria-label="Resumo do campus" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ([
            ['label' => 'Solicitações para analisar', 'value' => $overview['pendingRequests'], 'description' => 'Enviadas e em análise', 'icon' => 'clipboard-document-check', 'url' => route('internship-review.index')],
            ['label' => 'Em formalização', 'value' => $overview['formalization'], 'description' => 'Documentação em preparação', 'icon' => 'document-text', 'url' => route('internships.index', ['status' => 'pending_formalization'])],
            ['label' => 'Em andamento', 'value' => $overview['inProgress'], 'description' => 'Estágios em execução', 'icon' => 'play-circle', 'url' => route('internships.index', ['status' => 'in_progress'])],
            ['label' => 'Em pausa', 'value' => $overview['paused'], 'description' => 'Estágios com execução suspensa', 'icon' => 'pause-circle', 'url' => route('internships.index', ['status' => 'paused'])],
            ['label' => 'Concluídos', 'value' => $overview['completed'], 'description' => 'Estágios finalizados no campus', 'icon' => 'check-circle', 'url' => route('internships.index', ['status' => 'completed'])],
        ] as $metric)
            <x-dashboard-metric wire:key="office-metric-{{ $loop->index }}" :label="$metric['label']" :value="$metric['value']" :icon="$metric['icon']" :description="$metric['description']" :href="$metric['url']" />
        @endforeach
    </section>
    <div class="flex flex-wrap gap-3">
        <flux:button size="sm" variant="outline" icon="check-circle" :href="route('internships.index', ['status' => 'released'])" wire:navigate>Liberados para início: {{ $overview['released'] }}</flux:button>
        <flux:button size="sm" variant="outline" icon="pencil-square" :href="route('internships.index', ['status' => 'awaiting_signatures'])" wire:navigate>Aguardando assinaturas: {{ $overview['awaitingSignatures'] }}</flux:button>
    </div>
    <section class="space-y-4" aria-labelledby="office-requests-heading">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><flux:heading id="office-requests-heading" level="2" size="lg">Solicitações para analisar</flux:heading><flux:text class="mt-1" size="sm">Até 5 solicitações, das mais antigas às mais recentes pela última atualização.</flux:text></div>
            <flux:button variant="ghost" icon="arrow-right" :href="route('internship-review.index')" wire:navigate>Ver todas as solicitações</flux:button>
        </div>
        @if ($overview['requests']->isNotEmpty())
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Solicitação / Discente</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">Curso / Concedente</flux:table.column>
                    <flux:table.column>Situação</flux:table.column>
                    <flux:table.column class="hidden lg:table-cell">Última atualização</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($overview['requests'] as $request)
                        <flux:table.row :key="'office-request-'.$request->id" class="group cursor-pointer transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-office-request-link]').click()">
                            <flux:table.cell class="whitespace-normal">
                                <a data-office-request-link href="{{ route('internship-review.show', $request) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">Nº {{ $request->id }} · {{ $request->student_snapshot['name'] ?? 'Discente' }}</a>
                                <flux:text size="sm">Matrícula: {{ $request->student_snapshot['registration_number'] ?? 'Não informada' }}</flux:text>
                                <div class="space-y-1 md:hidden"><flux:text size="sm">Curso: {{ $request->course?->name ?? 'Não informado' }}</flux:text><flux:text size="sm">Concedente: {{ $request->granting_party_snapshot['name'] ?? 'Não informada' }}</flux:text></div>
                                <flux:text size="sm" class="lg:hidden">Atualizada: {{ formatDateTime($request->updated_at) }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell class="hidden whitespace-normal md:table-cell">{{ $request->course?->name }}<flux:text size="sm">{{ $request->granting_party_snapshot['name'] ?? 'Não informada' }}</flux:text></flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$request->status === \App\Enums\InternshipRequestStatus::UnderReview ? 'blue' : 'amber'">{{ $request->status->label() }}</flux:badge></flux:table.cell>
                            <flux:table.cell class="hidden lg:table-cell">{{ formatDateTime($request->updated_at) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <flux:callout icon="check-circle" heading="Nenhuma solicitação aguardando análise">Não há solicitações enviadas ou em análise neste campus.</flux:callout>
        @endif
    </section>
    <section class="space-y-4" aria-labelledby="office-endings-heading">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <flux:heading id="office-endings-heading" level="2" size="lg">Términos para acompanhar <span class="text-zinc-500">({{ $overview['endingCount'] }})</span></flux:heading>
                <flux:text class="mt-1" size="sm">Até 5 estágios liberados, em andamento ou em pausa com término previsto até {{ formatShort($overview['endingUntil']) }}. Inclui previsões anteriores a hoje; a data prevista não confirma a conclusão.</flux:text>
            </div>
            <flux:button variant="ghost" icon="arrow-right" :href="route('internships.index')" wire:navigate>Ver todos os estágios</flux:button>
        </div>
        @if ($overview['endingInternships']->isNotEmpty())
            <x-academic-internships-table :internships="$overview['endingInternships']" :is-office="true" />
        @else
            <flux:callout icon="calendar-days" heading="Nenhum término próximo">Não há estágios em execução com término previsto até essa data.</flux:callout>
        @endif
    </section>
</div>
