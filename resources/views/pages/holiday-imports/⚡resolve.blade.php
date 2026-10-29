<?php

use App\Models\Holiday;
use App\Models\HolidaySource;
use App\Support\HolidayAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Revisar feriado importado')] class extends Component
{
    use \App\Concerns\HandlesSearchFailures;

    protected function searchRetryProperties(): array
    {
        return ['candidates' => ['candidates']];
    }

    #[Locked]
    public HolidaySource $holidaySource;

    public string $holidaySearch = '';

    public string $selectedHolidayId = '';

    public function mount(HolidaySource $holidaySource): void
    {
        $this->holidaySource = $holidaySource;
        $this->selectedHolidayId = (string) old('holiday_id', '');
    }

    public function boot(): void
    {
        Gate::authorize('viewAny', HolidaySource::class);
    }

    /** @return Collection<int, Holiday> */
    #[Computed]
    public function candidates(): Collection
    {
        $search = mb_substr(trim($this->holidaySearch), 0, 255);
        $query = app(HolidayAccess::class)->query(auth()->user(), true);
        $suggestions = fn (): Collection => (clone $query)->whereIn('id', $this->holidaySource->candidate_ids ?? [])
            ->orderByDesc('date')->orderByDesc('id')->limit(20)->get();

        $candidates = mb_strlen($search) < 2 ? $suggestions() : $this->searchWithRecovery('candidates', function () use ($search, $query): Collection {
            $ids = Holiday::search($search)->withTrashed()->orderBy('date', 'desc')->orderBy('id', 'desc')->take(20)->keys();
            $records = (clone $query)->whereKey($ids->all())->limit(20)->get()->keyBy('id');

            return new Collection($ids->map(fn ($id) => $records->get($id))->filter()->values()->all());
        }, $suggestions);

        $selected = ctype_digit($this->selectedHolidayId) ? (clone $query)->find((int) $this->selectedHolidayId) : null;

        return $selected === null ? $candidates : $candidates->prepend($selected)->unique('id')->values();
    }
}; ?>

<div class="w-full space-y-8" x-data="{ resolution: @js(old('resolution', 'link')), submitting: false }"
    x-on:submit="submitting = true" x-on:pageshow.window="submitting = false">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('holidays.index')" wire:navigate>Feriados</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Revisar pendência</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Revisar feriado importado</flux:heading>
            <flux:text class="mt-2">{{ $holidaySource->payload['name'] }} · {{ formatShort($holidaySource->payload['date']) }} · Tipo informado: {{ $holidaySource->payload['type'] }}.</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            @if ($holidaySource->status === 'pending')
                <flux:button variant="outline" :href="route('holidays.index')" wire:navigate>Cancelar</flux:button>
                <flux:button type="submit" form="holiday-source-form" variant="primary" :loading="false" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                    <flux:icon.loading x-show="submitting" x-cloak class="size-4" />Registrar decisão
                </flux:button>
            @else
                <flux:button icon="arrow-left" variant="outline" :href="route('holidays.index')" wire:navigate>Voltar</flux:button>
            @endif
        </div>
    </div>
    @if ($holidaySource->status !== 'pending')
        <flux:text>Esta pendência já foi resolvida.</flux:text>
        @if ($holidaySource->resolution_notes)
            <flux:text>{{ $holidaySource->resolution_notes }}</flux:text>
        @endif
    @else
        <flux:callout>{{ $holidaySource->reason }}</flux:callout>
        <form data-unsaved-changes="native-editor" data-unsaved-initial-dirty="{{ $errors->any() ? 'true' : 'false' }}" id="holiday-source-form" method="POST" action="{{ route('holiday-imports.resolve.store', $holidaySource) }}" class="space-y-5" novalidate>
            @csrf
            <x-select name="resolution" label="Decisão" x-model="resolution" :value="old('resolution', 'link')"
                :options="['link' => 'Associar a um feriado existente', 'create' => 'Cadastrar como novo feriado', 'ignore' => 'Ignorar este item da fonte']"
                :invalid="$errors->has('resolution')" :error="$errors->first('resolution')" required />
            <fieldset x-cloak x-show="resolution === 'link'" x-bind:disabled="resolution !== 'link'" class="space-y-3">
                <x-select name="holiday_id" wire:model.live="selectedHolidayId" label="Feriado existente" placeholder="Selecione o feriado" :value="$selectedHolidayId"
                    :options="$this->candidates->mapWithKeys(fn ($candidate) => [$candidate->id => ((string) $candidate->id === $selectedHolidayId ? 'Seleção atual · ' : '').$candidate->name.' · '.formatShort($candidate->date).($candidate->trashed() ? ' · Na lixeira' : '')])->all()"
                    :search-unavailable="$this->searchFailed('candidates')" search-retry="retrySearch('candidates')" search-target="holidaySearch,retrySearch"
                    :invalid="$errors->has('holiday_id')" :error="$errors->first('holiday_id')"
                    :empty="mb_strlen(trim($holidaySearch)) < 2 ? 'Digite pelo menos 2 caracteres ou selecione uma sugestão.' : 'Nenhum feriado encontrado.'" searchable required>
                    <x-slot:search>
                        <flux:input name="" wire:model.live.debounce.500ms="holidaySearch" wire:ignore.self placeholder="Buscar feriado…" aria-label="Buscar feriado" icon="magnifying-glass" size="sm" maxlength="255"
                            role="combobox" aria-autocomplete="list" x-bind:aria-expanded="open" x-bind:aria-controls="$refs.list.id" x-bind:aria-activedescendant="activeId()" />
                        <p class="px-2 py-1 text-xs text-zinc-500 dark:text-zinc-400" role="status">
                            {{ mb_strlen(trim($holidaySearch)) >= 2 && ! $this->searchFailed('candidates')
                                ? 'Resultados da pesquisa: até 20 feriados. A seleção atual é mantida mesmo fora da busca. Refine o termo para encontrar outros.'
                                : 'Sugestões da importação: até 20 feriados, além da seleção atual.' }}
                        </p>
                        <span wire:loading wire:target="holidaySearch" class="block px-2 py-1 text-xs text-zinc-500" role="status">Buscando feriados…</span>
                    </x-slot:search>
                </x-select>
                <flux:text>A associação preserva os dados e a situação do feriado existente, inclusive se estiver na lixeira.</flux:text>
            </fieldset>
            <fieldset x-cloak x-show="resolution === 'create'" x-bind:disabled="resolution !== 'create'">
                <livewire:holidays.form-fields :defaults="['name' => $holidaySource->payload['name'], 'date' => $holidaySource->payload['date']]" />
            </fieldset>
            <flux:text x-cloak x-show="resolution === 'ignore'">Este item da fonte não será adicionado ao calendário.</flux:text>
            <flux:textarea name="resolution_notes" label="Justificativa" maxlength="5000" required>{{ old('resolution_notes') }}</flux:textarea>
            <flux:error name="source" />
        </form>
    @endif
</div>
