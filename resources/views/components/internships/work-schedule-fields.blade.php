<div class="space-y-4">
    <flux:checkbox wire:model.live="changesWorkSchedule" label="Este aditivo altera a jornada semanal." />
    @if ($changesWorkSchedule)
        <flux:input type="date" wire:model="scheduleStartsOn" label="Início da nova jornada" required /><flux:error name="starts_on" />
        <flux:text>A jornada será aplicada somente depois da conferência das assinaturas. A data deve suceder a jornada inicial e não pode estar no passado.</flux:text>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach (['sunday' => 'Domingo', 'monday' => 'Segunda', 'tuesday' => 'Terça', 'wednesday' => 'Quarta', 'thursday' => 'Quinta', 'friday' => 'Sexta', 'saturday' => 'Sábado'] as $day => $label)
                <div wire:key="new-work-schedule-{{ $keyPrefix }}-{{ $day }}"><flux:input type="number" min="0" max="24" step="1" wire:model="scheduleWeeklyHours.{{ $day }}" :label="$label.' (horas)'" required /><flux:error :name="'weekly_hours.'.$day" /></div>
            @endforeach
        </div>
        <flux:error name="weekly_hours" />
    @endif
</div>
