<div class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Meu painel</flux:heading>
            <flux:text class="mt-2">Acompanhe suas solicitações e os próximos passos do estágio.</flux:text>
        </div>
        <flux:badge color="zinc" icon="academic-cap">Discente</flux:badge>
    </div>

    <div class="flex min-w-0 items-start gap-3">
        <flux:icon.academic-cap class="mt-0.5 size-5 shrink-0 text-zinc-500 dark:text-zinc-400" />
        <div class="min-w-0">
            <flux:heading class="wrap-break-word">{{ $overview['course'] }}</flux:heading>
            <flux:text size="sm">{{ $overview['campus'] }}</flux:text>
        </div>
    </div>

    <section aria-label="Resumo das minhas solicitações e estágios" class="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => 'Para você revisar', 'value' => $overview['actionable'], 'description' => 'Rascunhos e pendências', 'icon' => 'pencil-square'],
            ['label' => 'Aguardando o Setor', 'value' => $overview['pending'], 'description' => 'Enviadas e em análise', 'icon' => 'clock'],
            ['label' => 'Meus estágios', 'value' => $overview['internshipsCount'], 'description' => 'Estágios registrados no seu vínculo', 'icon' => 'briefcase'],
            ['label' => 'Concluídos', 'value' => $overview['completed'], 'description' => 'Seus estágios finalizados', 'icon' => 'check-circle'],
        ] as $metric)
            <flux:card wire:key="student-metric-{{ $loop->index }}" class="flex min-w-0 items-center justify-between gap-3 p-3">
                <div class="min-w-0">
                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ $metric['label'] }}</flux:text>
                    <flux:heading size="xl">{{ $metric['value'] }}</flux:heading>
                    <flux:text size="sm">{{ $metric['description'] }}</flux:text>
                </div>
                <flux:avatar :icon="$metric['icon']" icon-variant="outline" size="lg" class="shrink-0 bg-brand/10 text-brand [&>svg]:opacity-100" />
            </flux:card>
        @endforeach
    </section>

    <div class="grid min-w-0 gap-4 lg:grid-cols-3">
        <flux:card class="flex min-w-0 flex-col items-start gap-4">
            <flux:avatar icon="clipboard-document-list" icon-variant="outline" class="bg-brand/10 text-brand [&>svg]:opacity-100" />
            <div>
                <flux:heading size="lg" level="2">Minhas solicitações</flux:heading>
                <flux:text class="mt-1">Inicie uma solicitação, retome seu rascunho ou confira a análise do Setor.</flux:text>
            </div>
            <flux:button variant="primary" icon="arrow-right" :href="route('internship-requests.index')" wire:navigate>Acessar solicitações</flux:button>
        </flux:card>
        <flux:card class="flex min-w-0 flex-col items-start gap-4">
            <flux:avatar icon="user-circle" icon-variant="outline" class="bg-brand/10 text-brand [&>svg]:opacity-100" />
            <div>
                <flux:heading size="lg" level="2">Meus dados pessoais</flux:heading>
                <flux:text class="mt-1">Confira seus documentos, telefone e endereço antes de enviar a solicitação.</flux:text>
            </div>
            <flux:button variant="outline" icon="user-circle" :href="route('personal-data.edit')" wire:navigate>Conferir meus dados</flux:button>
        </flux:card>
        <flux:card class="flex min-w-0 flex-col items-start gap-4">
            <flux:avatar icon="briefcase" icon-variant="outline" class="bg-brand/10 text-brand [&>svg]:opacity-100" />
            <div>
                <flux:heading size="lg" level="2">Meus estágios</flux:heading>
                <flux:text class="mt-1">Acompanhe a situação, o orientador e as datas previstas dos estágios aceitos.</flux:text>
            </div>
            <flux:button variant="outline" icon="briefcase" :href="route('student-internships.index')" wire:navigate>Acessar estágios</flux:button>
        </flux:card>
    </div>

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
