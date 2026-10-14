@props(['activeTab'])

<div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 dark:border-zinc-700">
    <flux:navbar aria-label="Calendário de feriados">
        <flux:navbar.item :href="route('holidays.index')" :current="$activeTab === 'holidays'" wire:navigate>Feriados</flux:navbar.item>
        @can('viewAny', \App\Models\HolidayRecurrence::class)
            <flux:navbar.item :href="route('holiday-recurrences.index')" :current="$activeTab === 'recurrences'" wire:navigate>Recorrências municipais</flux:navbar.item>
        @endcan
    </flux:navbar>
</div>
