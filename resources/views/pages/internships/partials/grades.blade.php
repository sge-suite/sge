<section class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="grades-heading">
    <flux:heading id="grades-heading" level="2" size="lg">Notas do estágio</flux:heading>
    @can('viewAdvisorGrades', $internship)
        <dl class="grid gap-5 sm:grid-cols-2">
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Nota do supervisor (máx. {{ $weights['supervisor_evaluation_weight'] ?? '—' }})</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $internship->supervisor_grade ?? 'Pendente' }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Nota consolidada (máx. 10)</dt><dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $internship->consolidated_grade ?? 'Pendente' }}</dd></div>
        </dl>
        @if ($weights === null)
            <flux:callout variant="warning" icon="information-circle" heading="Pesos das notas não configurados">Solicite a conferência dos pesos registrados neste estágio ao Setor de Estágios.</flux:callout>
        @elseif (auth()->user()->can('updateAdvisorGrades', $internship))
            <form wire:submit="saveGrades" class="space-y-5" data-unsaved-changes="internship-grades" data-unsaved-models='["reportGrade", "presentationGrade"]' data-unsaved-actions='["saveGrades"]'>
                @php($canUpdateGrades = auth()->user()->can('updateAdvisorGrades', $internship))
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <flux:input type="number" wire:model="reportGrade" label="Nota do relatório" min="0" :max="$weights['report_weight']" step="0.1" :disabled="! $canUpdateGrades || $weights['report_weight'] === 0" />
                        <flux:text size="sm" class="mt-1">Até {{ $weights['report_weight'] }} ponto(s).</flux:text>
                        <flux:error name="report_grade" />
                        @if ($internship->report_graded_at)<flux:text size="sm" class="mt-1">Último lançamento: {{ formatDateTime($internship->report_graded_at) }}</flux:text>@endif
                    </div>
                    <div>
                        <flux:input type="number" wire:model="presentationGrade" label="Nota da apresentação" min="0" :max="$weights['presentation_weight']" step="0.1" :disabled="! $canUpdateGrades || $weights['presentation_weight'] === 0" />
                        <flux:text size="sm" class="mt-1">Até {{ $weights['presentation_weight'] }} ponto(s).</flux:text>
                        <flux:error name="presentation_grade" />
                        @if ($internship->presentation_graded_at)<flux:text size="sm" class="mt-1">Último lançamento: {{ formatDateTime($internship->presentation_graded_at) }}</flux:text>@endif
                    </div>
                </div>
                <flux:text size="sm">As notas são contribuições diretas nos pesos registrados no estágio. A nota consolidada fica pendente enquanto faltar uma nota obrigatória ou a avaliação aprovada do supervisor.</flux:text>
                <flux:error name="grade_weights" /><flux:error name="supervisor_evaluation" />
                @if ($canUpdateGrades)<flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="saveGrades">Salvar notas</flux:button>@endif
            </form>
        @else
            <dl class="grid gap-5 sm:grid-cols-2">
                <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Nota do relatório</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $internship->report_grade ?? 'Pendente' }} / {{ $weights['report_weight'] }}</dd></div>
                <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Nota da apresentação</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $internship->presentation_grade ?? 'Pendente' }} / {{ $weights['presentation_weight'] }}</dd></div>
            </dl>
        @endif
    @else
        <flux:text>Nota consolidada: <span class="font-semibold">{{ $internship->consolidated_grade ?? 'Pendente' }}</span> / 10</flux:text>
    @endcan
</section>
