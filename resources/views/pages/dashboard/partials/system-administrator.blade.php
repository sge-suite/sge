<div class="w-full space-y-8">
    <x-dashboard-header description="Administrador do Sistema · Indicadores globais de todos os campi.">
        <x-slot:actions>
            <flux:button variant="primary" icon="users" :href="route('users.index')" wire:navigate>Usuários</flux:button>
            <flux:button variant="outline" icon="building-office-2" :href="route('campuses.index')" wire:navigate>Campi</flux:button>
        </x-slot:actions>
    </x-dashboard-header>

    <section aria-label="Totais do sistema" class="grid min-w-0 gap-3 sm:grid-cols-3">
        <x-dashboard-metric label="Usuários cadastrados" :value="$metrics['usersCount']" icon="users" :href="route('users.index')" />
        <x-dashboard-metric label="Vínculos cadastrados" :value="$metrics['affiliationsCount']" icon="link" :description="$metrics['activeAffiliationsCount'].' '.($metrics['activeAffiliationsCount'] === 1 ? 'ativo' : 'ativos')" />
        <x-dashboard-metric label="Campi cadastrados" :value="$metrics['campusesCount']" icon="building-office-2" :description="$metrics['activeCampusesCount'].' '.($metrics['activeCampusesCount'] === 1 ? 'ativo' : 'ativos')" :href="route('campuses.index')" />
    </section>

    <div class="grid min-w-0 gap-x-10 gap-y-6 xl:grid-cols-2">
        <section aria-labelledby="affiliations-by-type-heading" class="min-w-0 space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <div>
                    <flux:heading id="affiliations-by-type-heading" size="lg" level="2">Vínculos por tipo</flux:heading>
                    <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Todos os registros, inclusive desativados.</flux:text>
                </div>
                <flux:text class="text-sm tabular-nums">{{ $metrics['affiliationsCount'] }} no total</flux:text>
            </div>

            <div class="space-y-3">
                @foreach ($metrics['affiliationTypes'] as $type)
                    <div wire:key="affiliation-type-{{ $loop->index }}" class="min-w-0 space-y-1.5">
                        <div class="flex min-w-0 items-baseline justify-between gap-3">
                            <flux:text class="min-w-0 wrap-break-word text-sm">{{ $type['label'] }}</flux:text>
                            <flux:text class="shrink-0 text-sm tabular-nums">
                                {{ $type['value'] }}
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">({{ $type['active'] }} {{ $type['active'] === 1 ? 'ativo' : 'ativos' }})</span>
                            </flux:text>
                        </div>
                        <flux:progress :value="$type['value']" :max="$metrics['maxAffiliationTypeCount']" style="--flux-progress-color: var(--color-brand)" :aria-label="$type['label']" />
                    </div>
                @endforeach
            </div>
        </section>

        <section aria-labelledby="internships-by-status-heading" class="min-w-0 space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <div>
                    <flux:heading id="internships-by-status-heading" size="lg" level="2">Estágios por situação</flux:heading>
                    <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Cada situação do fluxo aparece, mesmo quando ainda não há estágio com esse status.</flux:text>
                </div>
                <flux:text class="text-sm tabular-nums">{{ $metrics['internshipsCount'] }} no total</flux:text>
            </div>

            <div class="space-y-3">
                @foreach ($metrics['internshipsByStatus'] as $status)
                    <div wire:key="internship-status-{{ $loop->index }}" class="min-w-0 space-y-1.5">
                        <div class="flex min-w-0 items-baseline justify-between gap-3">
                            <flux:text class="min-w-0 wrap-break-word text-sm">{{ $status['label'] }}</flux:text>
                            <flux:text class="shrink-0 text-sm tabular-nums">{{ $status['value'] }}</flux:text>
                        </div>
                        <flux:progress :value="$status['value']" :max="$metrics['maxInternshipStatusCount']" style="--flux-progress-color: var(--color-brand)" :aria-label="$status['label']" />
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    <div class="grid min-w-0 gap-x-10 gap-y-6 xl:grid-cols-2">
        <section aria-labelledby="documents-by-status-heading" class="space-y-3 border-t border-zinc-200 pt-5 dark:border-zinc-700">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <div>
                    <flux:heading id="documents-by-status-heading" size="lg" level="2">Documentos por situação</flux:heading>
                    <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Todos os documentos registrados no sistema.</flux:text>
                </div>
                <flux:text class="text-sm tabular-nums">{{ $metrics['documentsCount'] }} no total</flux:text>
            </div>

            <div class="grid min-w-0 gap-x-10 gap-y-3 sm:grid-cols-2">
                @foreach ($metrics['documentsByStatus'] as $status)
                    <div wire:key="document-status-{{ $loop->index }}" class="min-w-0 space-y-1.5">
                        <div class="flex min-w-0 items-baseline justify-between gap-3">
                            <flux:text class="min-w-0 wrap-break-word text-sm">{{ $status['label'] }}</flux:text>
                            <flux:text class="shrink-0 text-sm tabular-nums">{{ $status['value'] }}</flux:text>
                        </div>
                        <flux:progress :value="$status['value']" :max="$metrics['maxDocumentStatusCount']" style="--flux-progress-color: var(--color-brand)" :aria-label="$status['label']" />
                    </div>
                @endforeach
            </div>
        </section>

        <section aria-labelledby="pending-dashboard-heading" class="min-w-0 space-y-3 border-t border-zinc-200 pt-5 dark:border-zinc-700">
            <div>
                <flux:heading id="pending-dashboard-heading" size="lg" level="2">Solicitações em análise</flux:heading>
                <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Solicitações enviadas ou em análise.</flux:text>
            </div>

            <div class="space-y-3">
                <div class="min-w-0 space-y-1">
                    <div class="flex min-w-0 items-baseline justify-between gap-3">
                        <flux:text class="min-w-0 wrap-break-word text-sm">Solicitações de estágio</flux:text>
                        <flux:text class="shrink-0 text-sm tabular-nums">{{ $metrics['pendingInternshipRequestsCount'] }}</flux:text>
                    </div>
                    <flux:progress :value="$metrics['pendingInternshipRequestsCount']" :max="$metrics['maxPendingRequestsCount']" style="--flux-progress-color: var(--color-brand)" aria-label="Solicitações de estágio" />
                </div>
                <div class="min-w-0 space-y-1">
                    <div class="flex min-w-0 items-baseline justify-between gap-3">
                        <flux:text class="min-w-0 wrap-break-word text-sm">Cadastros de concedentes e supervisores</flux:text>
                        <flux:text class="shrink-0 text-sm tabular-nums">{{ $metrics['pendingRegistrationRequestsCount'] }}</flux:text>
                    </div>
                    <flux:progress :value="$metrics['pendingRegistrationRequestsCount']" :max="$metrics['maxPendingRequestsCount']" style="--flux-progress-color: var(--color-brand)" aria-label="Cadastros de concedentes e supervisores" />
                </div>
            </div>
        </section>
    </div>
</div>
