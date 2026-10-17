@props(['activities', 'description' => 'Alterações registradas neste item e, quando aplicável, nos seus vínculos administrativos.'])

@if ($activities !== null && $activities->isNotEmpty())
    <section class="space-y-4 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="audit-history-heading">
        <div>
            <flux:heading id="audit-history-heading" size="lg" level="2">Histórico de auditoria</flux:heading>
            <flux:text class="mt-1">{{ $description }}</flux:text>
        </div>
        <x-audit.activity-table :activities="$activities" />
    </section>
@endif
