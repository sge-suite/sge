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
