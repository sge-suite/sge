<div class="w-full space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading level="1" size="xl">Acompanhamento de estágios</flux:heading>
            <flux:text class="mt-2">{{ $overview['campus'] }} · Consulte os estágios disponíveis para o vínculo selecionado.</flux:text>
        </div>
        <flux:badge icon="academic-cap" color="zinc">{{ $overview['role'] }}</flux:badge>
    </div>
    <section aria-label="Resumo dos estágios" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => 'Estágios', 'value' => $overview['total'], 'icon' => 'briefcase'],
            ['label' => 'Em andamento', 'value' => $overview['inProgress'], 'icon' => 'play-circle'],
            ['label' => 'Em pausa', 'value' => $overview['paused'], 'icon' => 'pause-circle'],
            ['label' => 'Concluídos', 'value' => $overview['completed'], 'icon' => 'check-circle'],
        ] as $metric)
            <flux:card wire:key="academic-metric-{{ $loop->index }}" class="flex min-w-0 items-center justify-between gap-3 p-3">
                <div><flux:text>{{ $metric['label'] }}</flux:text><flux:heading size="xl">{{ $metric['value'] }}</flux:heading></div>
                <flux:avatar :icon="$metric['icon']" icon-variant="outline" size="lg" class="shrink-0 bg-brand/10 text-brand [&>svg]:opacity-100" />
            </flux:card>
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
