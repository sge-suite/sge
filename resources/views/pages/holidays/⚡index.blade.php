<?php

use App\Enums\BrazilianState;
use App\Enums\HolidayScope;
use App\Models\City;
use App\Models\Holiday;
use App\Models\HolidaySource;
use App\Support\HolidayAccess;
use App\Support\PageSize;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Feriados')] class extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url]
    public ?int $year = 0;

    #[Url]
    public string $scope = '';

    #[Url]
    public string $state = '';

    #[Url]
    public string $cityId = '';

    #[Url]
    public string $origin = '';

    #[Locked]
    public bool $trash = false;

    public string $citySearch = '';

    #[Locked]
    public ?Holiday $confirmationTarget = null;

    #[Locked]
    public string $confirmationOperation = 'delete';

    public bool $showConfirmation = false;

    public function openConfirmation(int $id, string $operation): void
    {
        abort_unless(in_array($operation, ['delete', 'restore'], true), 404);
        $target = app(HolidayAccess::class)->query(auth()->user(), true)->findOrFail($id);
        Gate::authorize($operation, $target);
        $this->confirmationTarget = $target;
        $this->confirmationOperation = $operation;
        $this->showConfirmation = true;
    }

    public function hydrate(): void
    {
        if ($this->confirmationTarget !== null) {
            Gate::authorize($this->confirmationOperation, $this->confirmationTarget);
        }
    }

    public function closeConfirmation(): void
    {
        $this->showConfirmation = false;
        $this->confirmationTarget = null;
        $this->resetValidation();
    }

    public function updatedShowConfirmation(bool $value): void
    {
        if (! $value) {
            $this->closeConfirmation();
        }
    }

    public function boot(): void
    {
        Gate::authorize('viewAny', Holiday::class);
    }

    public function mount(bool $trash = false): void
    {
        $this->trash = $trash;
        $this->year = request()->filled('year') ? max(1900, min(2199, (int) $this->year)) : ($this->trash ? null : now()->year);
    }

    public function updated(string $property): void
    {
        if ($property === 'state') {
            $this->cityId = '';
            $this->citySearch = '';
        }
        if ($property === 'citySearch') {
            $this->citySearch = mb_substr(trim($this->citySearch), 0, 255);

            return;
        }
        if ($property === 'cityId') {
            $this->citySearch = '';
        }
        if (in_array($property, ['search', 'year', 'scope', 'state', 'cityId', 'origin'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'scope', 'state', 'cityId', 'origin', 'citySearch');
        $this->year = $this->trash ? null : now()->year;
        $this->resetPage();
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->year !== ($this->trash ? null : now()->year)
            || $this->scope !== '' || $this->state !== '' || $this->cityId !== '' || $this->origin !== '';
    }

    #[Computed]
    public function selectedCity(): ?City
    {
        if (! ctype_digit($this->cityId)) {
            return null;
        }

        return app(HolidayAccess::class)->cities(auth()->user())
            ->when(BrazilianState::tryFrom($this->state) !== null, fn ($query) => $query->where('state', $this->state))
            ->find((int) $this->cityId);
    }

    #[Computed]
    public function cities(): Collection
    {
        $search = mb_substr(trim($this->citySearch), 0, 255);
        $state = BrazilianState::tryFrom($this->state);
        if (mb_strlen($search) < 2) {
            return new Collection;
        }
        $query = City::search($search);
        if ($state !== null) {
            $query->where('state', $state->value);
        }

        return $query->take(20)->get();
    }

    /** @return Collection<int, HolidaySource> */
    #[Computed]
    public function pendingSources(): Collection
    {
        if ($this->trash || ! app(HolidayAccess::class)->canManage(auth()->user())) {
            return new Collection;
        }

        return HolidaySource::query()->where('status', 'pending')->orderBy('id')->limit(5)->get();
    }

    #[Computed]
    public function hasTrash(): bool
    {
        return app(HolidayAccess::class)->query(auth()->user(), true)->onlyTrashed()->exists();
    }

    #[Computed]
    public function holidays(): LengthAwarePaginator
    {
        $query = app(HolidayAccess::class)->query(auth()->user(), $this->trash);
        if ($this->trash) {
            $query->onlyTrashed();
        }
        if ($this->year !== null && $this->year > 0) {
            $query->whereYear('date', max(1900, min(2199, $this->year)));
        }
        if (HolidayScope::tryFrom($this->scope)) {
            $query->where('scope', $this->scope);
        }
        if (in_array($this->origin, ['manual', 'brasil_api', 'recurrence'], true)) {
            $query->where('origin', $this->origin);
        }
        $city = $this->selectedCity;
        $state = $city?->state->value ?? BrazilianState::tryFrom($this->state)?->value;
        if ($this->cityId !== '' && $city === null) {
            $query->whereRaw('1 = 0');
        }
        if ($state !== null) {
            $query->where(fn ($query) => $query->where('scope', HolidayScope::National)->orWhere('state_code', $state));
        }
        if ($city !== null) {
            $query->where(fn ($query) => $query->where('scope', '!=', HolidayScope::Municipal)->orWhere('city_id', $city->id));
        }

        $search = mb_substr(trim($this->search), 0, 255);
        if ($search !== '' && ($this->cityId === '' || $city !== null)) {
            $searchQuery = Holiday::search($search);
            if ($this->trash) {
                $searchQuery->onlyTrashed();
            }
            if ($this->year !== null && $this->year > 0) {
                $searchQuery->where('year', max(1900, min(2199, $this->year)));
            }
            if (HolidayScope::tryFrom($this->scope)) {
                $searchQuery->where('scope', $this->scope);
            }
            if (in_array($this->origin, ['manual', 'brasil_api', 'recurrence'], true)) {
                $searchQuery->where('origin', $this->origin);
            }
            if ($state !== null) {
                $searchQuery->whereIn('state_code', ['', $state]);
            }
            if ($city !== null) {
                $searchQuery->whereIn('city_id', [0, (int) $city->id]);
            }

            return $searchQuery->orderBy('date')->orderBy('name')->orderBy('id')
                ->query(fn (Builder $query) => $query->with('city'))
                ->paginate(PageSize::PER_PAGE, 'page', $this->getPage());
        }

        return $query->with('city')->orderBy('date')->orderBy('name')->orderBy('id')->paginate(PageSize::PER_PAGE);
    }
}; ?>

<div class="w-full space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $trash ? 'Lixeira de feriados' : 'Feriados' }}</flux:heading>
            <flux:text class="mt-2">{{ $trash ? 'Feriados excluídos que deixaram de valer no calendário.' : 'Consulte os feriados nacionais, estaduais e municipais de todo o Brasil.' }}</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            @cannot('create', Holiday::class)<flux:badge>Somente leitura</flux:badge>@endcannot
            @if ($trash)<flux:button variant="outline" icon="arrow-left" :href="route('holidays.index')" wire:navigate>Voltar aos feriados</flux:button>
            @else
                @if ($this->hasTrash)<flux:button variant="outline" icon="trash" :href="route('holidays.trash')" wire:navigate>Lixeira</flux:button>@endif
                @can('create', Holiday::class)<flux:button variant="primary" icon="plus" :href="route('holidays.create')" wire:navigate>Cadastrar feriado</flux:button>@endcan
            @endif
        </div>
    </div>
    <x-holidays.navigation />
    @if (session('status'))
        <div x-data x-init="$nextTick(() => $flux.toast(@js(session('status')), { variant: 'success' }))"></div>
    @endif
    @foreach (['holiday', 'name'] as $error)<flux:error :name="$error" />@endforeach
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-[7rem_10rem_14rem_minmax(14rem,1fr)_16rem] items-end">
        <flux:input type="number" min="1900" max="2199" wire:model.live.debounce.400ms="year" :label="$trash ? 'Ano (opcional)' : 'Ano'" placeholder="Todos os anos" />
        <x-select wire:model.live="scope" label="Escopo" :value="$scope" :options="['' => 'Todos', ...HolidayScope::options()]" />
        <x-select wire:model.live="state" label="UF" :value="$state" :options="['' => 'Todas as UFs'] + collect(BrazilianState::options())->map(fn ($label, $value) => $label.' ('.$value.')')->all()" searchable />
        <div class="min-w-0">
            <x-select wire:model.live="cityId" label="Cidade" icon="map-pin" placeholder="Todas as cidades"
                :value="$cityId" :selected-label="$this->selectedCity ? $this->selectedCity->name.' / '.$this->selectedCity->state->value : ''"
                :options="($cityId !== '' ? ['' => 'Todas as cidades'] : []) + $this->cities->mapWithKeys(fn ($city) => [$city->id => $city->name.' / '.$city->state->value])->all()"
                :empty="mb_strlen(trim($citySearch)) < 2 ? 'Digite pelo menos 2 caracteres para buscar.' : 'Nenhuma cidade encontrada.'"
                searchable wire:key="holiday-city-select-{{ $state }}" wire:loading.attr="disabled" wire:target="state,cityId">
                <x-slot:search>
                    <flux:input name="" wire:model.live.debounce.500ms="citySearch" wire:ignore.self placeholder="Buscar cidade…" aria-label="Buscar cidade" icon="magnifying-glass" size="sm" maxlength="255"
                        role="combobox" aria-autocomplete="list" x-bind:aria-expanded="open" x-bind:aria-controls="$refs.list.id" x-bind:aria-activedescendant="activeId()" />
                    <span wire:loading wire:target="citySearch" class="block px-2 py-1 text-xs text-zinc-500" role="status">Buscando cidades…</span>
                </x-slot:search>
            </x-select>
        </div>
        <x-select wire:model.live="origin" label="Origem" :value="$origin" :options="['' => 'Todas', 'manual' => 'Manual', 'brasil_api' => 'BrasilAPI', 'recurrence' => 'Recorrência']" />
    </div>
    <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_9rem] sm:items-end">
        <flux:input wire:model.live.debounce.300ms="search" label="Buscar por nome" icon="magnifying-glass" maxlength="255" type="search" />
        <div>
            @if ($this->hasFilters)
                <flux:button class="w-full" variant="outline" wire:click="clearFilters">Limpar filtros</flux:button>
            @else
                <div aria-hidden="true" class="h-10"></div>
            @endif
        </div>
    </div>
    @php($holidays = $this->holidays)
    <x-loading-overlay target="search,year,scope,state,cityId,origin,clearFilters,gotoPage,nextPage,previousPage,resetPage,openConfirmation" label="Atualizando calendário…" :dim-while-loading="true">
        @if ($holidays->isNotEmpty())
            <flux:table :paginate="$holidays">
                <flux:table.columns>
                    <flux:table.column>Data</flux:table.column>
                    <flux:table.column>Nome</flux:table.column>
                    <flux:table.column>Localização</flux:table.column>
                    <flux:table.column class="hidden lg:table-cell">Origem</flux:table.column>
                    @can('create', Holiday::class)<flux:table.column>Ações</flux:table.column>@endcan
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($holidays as $holiday)
                        <flux:table.row :key="$holiday->id">
                            <flux:table.cell>{{ formatShort($holiday->date) }}</flux:table.cell>
                            <flux:table.cell class="whitespace-normal">
                                {{ $holiday->name }}
                                <flux:text size="sm" class="mt-1 lg:hidden">{{ $holiday->originLabel() }}{{ $holiday->is_manually_adjusted ? ' · Ajustado manualmente' : '' }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-normal">
                                {{ $holiday->scope->label() }}
                                {{ $holiday->city ? $holiday->city->name.' / '.$holiday->city->state->value : $holiday->state_code?->value }}
                            </flux:table.cell>
                            <flux:table.cell class="hidden lg:table-cell">
                                {{ $holiday->originLabel() }}
                                @if ($holiday->is_manually_adjusted)<flux:badge size="sm">Ajustado</flux:badge>@endif
                            </flux:table.cell>
                            @can('create', Holiday::class)
                                <flux:table.cell>
                                    <div class="flex flex-wrap items-center gap-2">
                                        @can('update', $holiday)<flux:button size="sm" variant="outline" icon="pencil-square" :href="route('holidays.edit', $holiday)" wire:navigate>Editar</flux:button>@endcan
                                        @can('delete', $holiday)<flux:button size="sm" variant="ghost" icon="trash" wire:click="openConfirmation({{ $holiday->id }}, 'delete')">Excluir</flux:button>@endcan
                                        @can('restore', $holiday)<flux:button size="sm" variant="outline" icon="arrow-path" wire:click="openConfirmation({{ $holiday->id }}, 'restore')">Restaurar</flux:button>@endcan
                                    </div>
                                </flux:table.cell>
                            @endcan
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
                <flux:icon.tent-tree class="mx-auto mb-4 size-8 text-zinc-400" />
                @if ($holidays->total() > 0)
                    <flux:heading size="lg">Nenhum feriado nesta página</flux:heading>
                    <flux:button class="mt-5" variant="ghost" wire:click="resetPage">Ir para a primeira página</flux:button>
                @elseif ($this->hasFilters)
                    <flux:heading size="lg">Nenhum feriado encontrado</flux:heading>
                    <flux:text class="mt-2">Ajuste a busca, o ano, o escopo, a localização ou a origem.</flux:text>
                    <flux:button class="mt-5" variant="ghost" wire:click="clearFilters">Limpar filtros</flux:button>
                @elseif ($trash)
                    <flux:heading size="lg">A lixeira está vazia</flux:heading>
                    <flux:text class="mt-2">Os feriados excluídos aparecerão aqui.</flux:text>
                @else
                    <flux:heading size="lg">Nenhum feriado cadastrado{{ $year ? ' para '.$year : '' }}</flux:heading>
                    <flux:text class="mt-2">Os feriados disponíveis aparecerão nesta lista.</flux:text>
                    @can('create', Holiday::class)
                        <flux:button class="mt-5" :href="route('holidays.create')" wire:navigate>Cadastrar feriado</flux:button>
                    @endcan
                @endif
            </div>
        @endif
    </x-loading-overlay>
    @if ($this->pendingSources->isNotEmpty())
        <section class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading level="2">Feriados que precisam de revisão</flux:heading>
            <flux:text>A fonte enviou dados que precisam de uma decisão antes de entrar no calendário.</flux:text>
            @foreach ($this->pendingSources as $source)
                <div wire:key="pending-holiday-{{ $source->id }}" class="flex flex-wrap items-center justify-between gap-3">
                    <flux:text>{{ $source->payload['name'] }} · {{ formatShort($source->payload['date']) }}</flux:text>
                    <flux:button size="sm" variant="outline" :href="route('holiday-imports.resolve', $source)" wire:navigate>Revisar</flux:button>
                </div>
            @endforeach
        </section>
    @endif
    <x-holidays.confirmation :target="$confirmationTarget" :operation="$confirmationOperation" kind="holidays" />
</div>
