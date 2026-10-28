<div class="w-full space-y-8">
    <x-dashboard-header :description="$overview['course'].' · '.$overview['campus'].' · Acompanhe suas solicitações e estágios.'">
        <x-slot:actions>
            <flux:button variant="primary" icon="clipboard-document-list" :href="route('internship-requests.index')" wire:navigate>Minhas solicitações</flux:button>
            <flux:button variant="outline" icon="briefcase" :href="route('student-internships.index')" wire:navigate>Meus estágios</flux:button>
            <flux:button variant="outline" icon="user-circle" :href="route('personal-data.edit')" wire:navigate>Meus dados</flux:button>
        </x-slot:actions>
    </x-dashboard-header>

    <section aria-label="Resumo das minhas solicitações e estágios" class="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => 'Para você revisar', 'value' => $overview['actionable'], 'description' => 'Rascunhos e pendências', 'icon' => 'pencil-square'],
            ['label' => 'Aguardando o Setor', 'value' => $overview['pending'], 'description' => 'Enviadas e em análise', 'icon' => 'clock'],
            ['label' => 'Meus estágios', 'value' => $overview['internshipsCount'], 'description' => 'Estágios registrados no seu vínculo', 'icon' => 'briefcase'],
            ['label' => 'Concluídos', 'value' => $overview['completed'], 'description' => 'Seus estágios finalizados', 'icon' => 'check-circle'],
        ] as $metric)
            <x-dashboard-metric wire:key="student-metric-{{ $loop->index }}" :label="$metric['label']" :value="$metric['value']" :icon="$metric['icon']" :description="$metric['description']" />
        @endforeach
    </section>

    <section aria-labelledby="student-recent-requests" class="space-y-4 border-t border-zinc-200 pt-6 dark:border-zinc-700">
        <div>
            <flux:heading id="student-recent-requests" size="lg" level="2">Últimas atualizações</flux:heading>
            <flux:text class="mt-1">As três solicitações e os três estágios atualizados mais recentemente.</flux:text>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading level="3">Solicitações</flux:heading>
            <flux:button variant="ghost" icon="arrow-right" :href="route('internship-requests.index')" wire:navigate>Ver todas as solicitações</flux:button>
        </div>
        @if ($overview['requests']->isNotEmpty())
            <x-student-requests-table :requests="$overview['requests']" :can-edit="$overview['canEdit']" />
        @else
            <flux:callout icon="clipboard-document-list" heading="Nenhuma solicitação iniciada">
                <flux:callout.text>Inicie sua solicitação para acompanhar as atualizações por aqui.</flux:callout.text>
            </flux:callout>
        @endif
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-5 dark:border-zinc-700">
            <flux:heading level="3">Estágios</flux:heading>
            <flux:button variant="ghost" icon="arrow-right" :href="route('student-internships.index')" wire:navigate>Ver todos os estágios</flux:button>
        </div>
        @if ($overview['internships']->isNotEmpty())
            <x-student-internships-table :internships="$overview['internships']" />
        @else
            <flux:callout icon="briefcase" heading="Nenhum estágio registrado">
                <flux:callout.text>Os estágios aparecerão aqui após o aceite das suas solicitações.</flux:callout.text>
            </flux:callout>
        @endif
    </section>
</div>
