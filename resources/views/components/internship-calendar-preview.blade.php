@props(['result' => null, 'editing' => false, 'saved' => false, 'historical' => false])

<flux:modal {{ $attributes->class(['w-[calc(100vw-2rem)] max-w-5xl! max-h-[calc(100dvh-2rem)]']) }}>
    <div class="space-y-6">
        <div>
            <flux:heading size="lg" level="2">Calendário do estágio</flux:heading>
            <flux:text class="mt-2">{{ $historical ? 'Reprodução com os dados e o calendário preservados no cálculo original.' : ($saved ? 'Calendário calculado com os dados salvos do estágio.' : ($editing ? 'Prévia com os dados do formulário. Salve as alterações para atualizar o estágio.' : 'Consulta com o calendário atualmente cadastrado. Esta consulta não altera o término salvo.')) }}</flux:text>
        </div>
        @if ($result !== null)
            <dl class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Carga exigida</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $result['credited_hours'] }} h</dd></div>
                <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Conclusão das horas</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ formatShort($result['completion_date']) }}</dd></div>
                <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Margem de segurança</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $result['margin_days'] }} dias corridos</dd></div>
                <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Término previsto</dt><dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ formatShort($result['projected_end_date']) }}</dd></div>
            </dl>
            <flux:text>Calendário de {{ $result['location'] }}. O último dia de cômputo considera apenas as horas que faltam para completar a carga exigida.</flux:text>
            <flux:text>Após a margem de {{ $result['margin_days'] }} dias corridos, o término é ajustado para o primeiro dia com jornada de estágio, respeitando feriados, pausas e exceções do calendário.</flux:text>
            @if (! $editing && $result['projected_end_date'] !== $result['saved_projected_end_date'])
                <flux:callout icon="information-circle" heading="O calendário atual resulta em outra previsão">
                    <flux:callout.text>O término salvo é {{ formatShort($result['saved_projected_end_date']) }}. Para atualizá-lo, abra a edição do estágio, confira a prévia do calendário e salve as alterações.</flux:callout.text>
                </flux:callout>
            @endif
            <div class="max-h-[55dvh] overflow-auto rounded-lg border border-zinc-200 dark:border-zinc-700" tabindex="0" aria-label="Dias e horas previstos para o estágio">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Data</flux:table.column>
                        <flux:table.column>Dia da semana</flux:table.column>
                        <flux:table.column>Situação do dia</flux:table.column>
                        <flux:table.column align="end">Jornada</flux:table.column>
                        <flux:table.column align="end">Horas computadas</flux:table.column>
                        <flux:table.column align="end">Acumulado</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($result['calendar'] as $day)
                            <flux:table.row :key="'calendar-'.$day['date']" class="{{ $day['date'] === $result['projected_end_date'] ? 'bg-blue-50 dark:bg-blue-950/30' : '' }}">
                                <flux:table.cell>{{ formatShort($day['date']) }}</flux:table.cell>
                                <flux:table.cell>{{ \App\Helpers\DateHelper::formatWeekday($day['date']) }}</flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:badge size="sm" :color="match ($day['kind']) { 'work_day' => 'green', 'blocked' => 'orange', 'projected_end' => 'blue', default => 'zinc' }">{{ $day['reason'] }}</flux:badge>
                                        @if ($day['date'] === $result['projected_end_date'] && $day['kind'] !== 'projected_end')<flux:badge size="sm" color="blue">Término previsto</flux:badge>@endif
                                        @if ($day['date'] === $result['completion_date'])<span class="text-xs text-zinc-500 dark:text-zinc-400">Carga completa</span>@endif
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell align="end">{{ $day['scheduled_hours'] }} h</flux:table.cell>
                                <flux:table.cell align="end">{{ $day['credited_hours'] }} h</flux:table.cell>
                                <flux:table.cell align="end">{{ $day['accumulated_hours'] }} h</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
        <div class="flex justify-end"><flux:modal.close><flux:button variant="outline">Fechar</flux:button></flux:modal.close></div>
    </div>
</flux:modal>
