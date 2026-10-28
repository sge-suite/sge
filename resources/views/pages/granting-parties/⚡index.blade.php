<?php

use App\Enums\BrazilianState;
use App\Models\City;
use App\Models\GrantingParty;
use App\Support\ActiveAffiliationContext;
use App\Support\PageSize;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Partes concedentes')] class extends Component
{
    use WithPagination;

    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $state = 'all';

    #[Url(except: '', as: 'city')]
    public string $cityId = '';

    public string $citySearch = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedState(): void
    {
        if ($this->state !== 'all' && BrazilianState::tryFrom($this->state) === null) {
            $this->state = 'all';
        }

        $this->cityId = '';
        $this->citySearch = '';
        $this->resetPage();
    }

    public function updatedCityId(): void
    {
        if ($this->cityId !== '' && $this->selectedCity === null) {
            $this->cityId = '';
        }

        $this->citySearch = '';
        $this->resetPage();
    }

    public function updatedCitySearch(): void
    {
        $this->citySearch = mb_substr(trim($this->citySearch), 0, 255);
    }

    public function boot(): void
    {
        Gate::authorize('viewAny', GrantingParty::class);
    }

    public function mount(): void
    {
        if (! in_array($this->status, ['all', 'active', 'inactive'], true)) {
            $this->status = 'all';
        }

        if ($this->state !== 'all' && BrazilianState::tryFrom($this->state) === null) {
            $this->state = 'all';
        }

        if ($this->selectedCity === null) {
            $this->cityId = '';
        }
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'search', 'state', 'cityId', 'citySearch');
        $this->resetPage();
    }

    #[Computed]
    public function selectedCity(): ?City
    {
        $key = filter_var($this->cityId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $state = BrazilianState::tryFrom($this->state);

        if ($key === false) {
            return null;
        }

        return City::query()->when($state !== null, fn (Builder $query) => $query->forState($state))->find($key);
    }

    /** @return Collection<int, City> */
    #[Computed]
    public function cities(): Collection
    {
        $state = BrazilianState::tryFrom($this->state);
        $search = mb_substr(trim($this->citySearch), 0, 255);

        if (mb_strlen($search) < 2) {
            return new Collection;
        }

        $query = City::search($search);

        if ($state !== null) {
            $query->where('state', $state->value);
        }

        return $query->take(20)->get();
    }

    #[Computed]
    public function grantingParties(): LengthAwarePaginator
    {
        Gate::authorize('viewAny', GrantingParty::class);
        $affiliation = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'));
        $state = $this->state === 'all' ? null : BrazilianState::tryFrom($this->state);
        $selectedCity = $this->selectedCity;

        $search = mb_substr(trim($this->search), 0, 255);
        $query = GrantingParty::query()->where('campus_id', $affiliation->campus_id)
            ->with('address.city')
            ->when($this->status === 'active', fn (Builder $query) => $query->whereNull('deactivated_at'))
            ->when($this->status === 'inactive', fn (Builder $query) => $query->whereNotNull('deactivated_at'))
            ->when($state !== null || $selectedCity !== null, function (Builder $query) use ($state, $selectedCity): void {
                $query->whereHas('address.city', function (Builder $query) use ($state, $selectedCity): void {
                    if ($state !== null) {
                        $query->where('state', $state->value);
                    }

                    if ($selectedCity !== null) {
                        $query->whereKey($selectedCity->getKey());
                    }
                });
            });

        if ($search !== '') {
            return GrantingParty::search($search)->where('campus_id', (int) $affiliation->campus_id)
                ->options(['attributesToSearchOn' => ['name', 'document_number', 'normalized_document_number']])
                ->when($this->status !== 'all', fn ($searchQuery) => $searchQuery->where('is_active', (int) ($this->status === 'active')))
                ->when($state !== null, fn ($searchQuery) => $searchQuery->where('state', $state->value))
                ->when($selectedCity !== null, fn ($searchQuery) => $searchQuery->where('city_id', (int) $selectedCity->id))
                ->query(fn (Builder $builder) => $builder->mergeConstraintsFrom($query)->with('address.city'))
                ->orderBy('name')->orderBy('id')->paginate(PageSize::PER_PAGE, 'page', $this->getPage());
        }

        return $query->orderBy('name')->orderBy('id')->paginate(PageSize::PER_PAGE);
    }
}; ?>

<div class="w-full space-y-8">
    @if (session('status'))
        <div hidden x-data x-init="$nextTick(() => $flux.toast(@js(session('status')), { variant: 'success' }))"></div>
    @endif
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Partes concedentes</flux:heading>
            <flux:text class="mt-2">Gerencie as partes concedentes do seu campus.</flux:text>
        </div>
        @can('create', \App\Models\GrantingParty::class)
            <div class="flex flex-wrap items-center gap-3">
                <flux:button icon="arrow-up-tray" variant="outline" :href="route('granting-parties.import')" wire:navigate>Importar CSV</flux:button>
                <flux:button variant="primary" icon="plus" :href="route('granting-parties.create')" wire:navigate>Cadastrar parte concedente</flux:button>
            </div>
        @else
            <flux:badge>Campus somente para leitura</flux:badge>
        @endcan
    </div>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_10rem_14rem_12rem_9rem] xl:items-end">
        <div class="min-w-0">
            <flux:input wire:model.live.debounce.300ms="search" label="Buscar por nome ou CPF/CNPJ completo" icon="magnifying-glass" maxlength="255" type="search" />
        </div>
        <div class="min-w-0">
            <x-select wire:model.live="state" label="UF" :value="$state" :options="['all' => 'Todas as UFs'] + collect(BrazilianState::options())->map(fn ($label, $value) => $label.' ('.$value.')')->all()" searchable />
        </div>
        <div class="min-w-0">
            <x-select wire:model.live="cityId" label="Cidade" icon="map-pin" placeholder="Todas as cidades"
                :value="$cityId" :selected-label="$this->selectedCity ? $this->selectedCity->name.' / '.$this->selectedCity->state->value : ''" :options="($cityId !== '' ? ['' => 'Todas as cidades'] : []) + $this->cities->mapWithKeys(fn ($city) => [$city->id => $city->name.' / '.$city->state->value])->all()"
                :empty="mb_strlen(trim($citySearch)) < 2 ? 'Digite pelo menos 2 caracteres para buscar.' : 'Nenhuma cidade encontrada.'"
                searchable wire:key="granting-party-city-select-{{ $state }}" wire:loading.attr="disabled" wire:target="state,cityId,citySearch">
                <x-slot:search>
                    <flux:input name="" wire:model.live.debounce.500ms="citySearch" wire:ignore.self placeholder="Buscar cidade…" aria-label="Buscar cidade" icon="magnifying-glass" size="sm" maxlength="255"
                        role="combobox" aria-autocomplete="list" x-bind:aria-expanded="open" x-bind:aria-controls="$refs.list.id" x-bind:aria-activedescendant="activeId()" />
                    <span wire:loading wire:target="citySearch" class="block px-2 py-1 text-xs text-zinc-500" role="status">Buscando cidades…</span>
                </x-slot:search>
            </x-select>
        </div>
        <div class="min-w-0">
            <x-select wire:model.live="status" label="Situação" :value="$status" :options="['all' => 'Todos', 'active' => 'Ativos', 'inactive' => 'Desativados']" />
        </div>
        <div class="min-w-0">
            @if ($search !== '' || $status !== 'all' || $state !== 'all' || $cityId !== '')
                <flux:button class="w-full" variant="outline" wire:click="clearFilters">Limpar filtros</flux:button>
            @else
                <div aria-hidden="true" class="h-10"></div>
            @endif
        </div>
    </div>
    @php($grantingParties = $this->grantingParties)
    <x-loading-overlay target="search,state,cityId,status,clearFilters,gotoPage,nextPage,previousPage,resetPage" label="Atualizando lista…" :dim-while-loading="true">
        @if ($grantingParties->isNotEmpty())
            <flux:table :paginate="$grantingParties">
                <flux:table.columns class="bg-white dark:bg-zinc-800">
                    <flux:table.column>Parte concedente</flux:table.column>
                    <flux:table.column>Documento</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">Cidade / UF</flux:table.column>
                    <flux:table.column>Situação</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($grantingParties as $grantingParty)
                        <flux:table.row :key="$grantingParty->id" class="group cursor-pointer transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-grantingParty-link]').click()">
                            <flux:table.cell class="whitespace-normal"><a data-grantingParty-link href="{{ route('granting-parties.show', $grantingParty) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">{{ $grantingParty->name }}</a><flux:text size="sm" class="md:hidden">{{ $grantingParty->address->city->name }} / {{ $grantingParty->address->city->state->value }}</flux:text></flux:table.cell>
                            <flux:table.cell>{{ $grantingParty->document_type->label() }} · {{ \App\Helpers\BrazilianDocumentHelper::formatCpfCnpj($grantingParty->document_number) }}</flux:table.cell>
                            <flux:table.cell class="hidden md:table-cell">{{ $grantingParty->address->city->name }} / {{ $grantingParty->address->city->state->value }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$grantingParty->deactivated_at === null ? 'green' : 'zinc'">{{ $grantingParty->deactivated_at === null ? 'Ativo' : 'Desativado' }}</flux:badge></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
                <flux:icon.building-office class="mx-auto mb-4 size-8 text-zinc-400" />
                @if ($grantingParties->total() > 0)
                    <flux:heading size="lg">Nenhuma parte concedente nesta página</flux:heading>
                    <flux:button class="mt-5" variant="ghost" wire:click="resetPage">Ir para a primeira página</flux:button>
                @elseif ($status !== 'all' || $search !== '' || $state !== 'all' || $cityId !== '')
                    <flux:heading size="lg">Nenhuma parte concedente encontrada</flux:heading>
                    <flux:text class="mt-2">Ajuste a busca, a localização ou a situação para encontrar uma parte concedente.</flux:text>
                    <flux:button class="mt-5" variant="ghost" wire:click="clearFilters">Limpar filtros</flux:button>
                @else
                    <flux:heading size="lg">Nenhuma parte concedente cadastrada</flux:heading>
                    <flux:text class="mt-2">As partes concedentes do seu campus aparecerão aqui.</flux:text>
                    @can('create', \App\Models\GrantingParty::class)
                        <flux:button class="mt-5" :href="route('granting-parties.create')" wire:navigate>Cadastrar parte concedente</flux:button>
                    @endcan
                @endif
            </div>
        @endif
    </x-loading-overlay>
</div>
