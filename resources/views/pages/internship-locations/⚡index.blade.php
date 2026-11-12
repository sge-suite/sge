<?php

use App\Concerns\HandlesSearchFailures;
use App\Models\Address;
use App\Models\Affiliation;
use App\Models\City;
use App\Models\GrantingParty;
use App\Support\PageSize;
use App\Support\StudentInternshipContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Locais de estágio')] class extends Component
{
    use HandlesSearchFailures;
    use WithPagination;

    #[Locked]
    public int $affiliationId;

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'city', except: '')]
    public string $cityId = '';

    #[Url(as: 'my_course', except: false)]
    public bool $myCourse = false;

    protected Affiliation $affiliation;

    public function boot(StudentInternshipContext $context): void
    {
        Gate::authorize('viewStudentLocations', GrantingParty::class);
        $affiliation = $context->student(Auth::user());
        abort_if($affiliation === null, 403);
        $this->affiliation = $affiliation;
    }

    public function mount(): void
    {
        $this->affiliationId = $this->affiliation->id;
    }

    public function hydrate(): void
    {
        abort_unless($this->affiliation->id === $this->affiliationId, 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCityId(): void
    {
        $this->resetPage();
    }

    public function updatedMyCourse(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'cityId', 'myCourse');
        $this->resetPage();
    }

    /** @return array<string, list<string>> */
    protected function searchRetryProperties(): array
    {
        return ['locations' => ['locations']];
    }

    /** @return array<int, string> */
    #[Computed]
    public function municipalities(): array
    {
        $cityIds = Address::query()->whereIn('id', $this->eligibleParties()->select('address_id'))->select('city_id');

        return City::query()->whereIn('id', $cityIds)->orderBy('name')->orderBy('state')->orderBy('id')
            ->get(['id', 'name', 'state'])
            ->mapWithKeys(fn (City $city): array => [$city->id => $city->name.' / '.$city->state->value])
            ->all();
    }

    /** @return LengthAwarePaginator<int, stdClass> */
    #[Computed]
    public function locations(): LengthAwarePaginator
    {
        $parties = $this->eligibleParties()
            ->when($this->myCourse, fn (Builder $query) => $query->withStartedInternshipsForCourse((int) $this->affiliation->course_id));
        $partyId = $parties->qualifyColumn('id');
        $partyName = $parties->qualifyColumn('name');
        $address = new Address;
        $city = new City;

        $parties->join($address->getTable(), $address->qualifyColumn('id'), '=', $parties->qualifyColumn('address_id'))
            ->join($city->getTable(), $city->qualifyColumn('id'), '=', $address->qualifyColumn('city_id'));

        $search = mb_substr(trim($this->search), 0, 255);
        $cityId = null;
        if ($this->cityId !== '') {
            $cityId = array_key_exists($this->cityId, $this->municipalities) ? (int) $this->cityId : 0;
            $parties->where($address->qualifyColumn('city_id'), $cityId);
        }

        if ($search !== '' && $this->municipalities !== [] && $cityId !== 0) {
            $ids = $this->searchWithRecovery('locations',
                fn (): array => $this->matchingIds($parties, $search, $cityId),
                fn (): array => [],
            );
            $parties->whereKey($ids);
        }

        return $parties->orderBy($partyName)->orderBy($partyId)->toBase()->paginate(PageSize::PER_PAGE, [
            $partyId, $partyName.' as name', $city->qualifyColumn('name').' as municipality', $city->qualifyColumn('state'),
        ]);
    }

    /** @return Builder<GrantingParty> */
    private function eligibleParties(): Builder
    {
        return GrantingParty::query()->forCampus((int) $this->affiliation->campus_id)->active();
    }

    /**
     * @param  Builder<GrantingParty>  $parties
     * @return list<int>
     */
    private function matchingIds(Builder $parties, string $term, ?int $cityId): array
    {
        $search = GrantingParty::search($term)
            ->options(['attributesToSearchOn' => ['name'], 'attributesToRetrieve' => ['id'], 'matchingStrategy' => 'all'])
            ->where('campus_id', (int) $this->affiliation->campus_id)
            ->where('is_active', 1)
            ->when($cityId !== null, fn ($query) => $query->where('city_id', $cityId));

        $limit = (int) config('scout.meilisearch.index-settings.'.GrantingParty::class.'.pagination.maxTotalHits', 1000);
        $ids = $search->take($limit)->keys();

        if ($ids->count() < $limit) {
            return $ids->all();
        }

        // Divide os IDs elegíveis em lotes quando a busca atinge o limite de resultados do índice.
        $partyId = $parties->qualifyColumn('id');
        return (clone $parties)->select($partyId)->toBase()->lazyById($limit, $partyId, 'id')->chunk($limit)
            ->flatMap(fn ($batch) => (clone $search)->whereIn('id', $batch->pluck('id')->all())->keys())
            ->values()->all();
    }
};
?>

<div class="w-full space-y-8">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>Painel</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Locais de estágio</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <div>
        <flux:heading level="1" size="xl">Locais de estágio</flux:heading>
        <flux:text class="mt-2">Consulte as partes concedentes cadastradas no seu campus. O cadastro não indica oferta de vaga.</flux:text>
    </div>

    @php($hasFilters = trim($search) !== '' || $cityId !== '' || $myCourse)
    @php($hasInvalidCity = $cityId !== '' && ! isset($this->municipalities[$cityId]))
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_18rem_9rem] xl:items-end">
        <flux:input wire:model.live.debounce.300ms="search" label="Buscar por nome" icon="magnifying-glass" maxlength="255" type="search" />
        <x-select wire:model.live="cityId" label="Município" icon="map-pin" :placeholder="$hasInvalidCity ? 'Município indisponível' : 'Todos os municípios'"
            :value="$hasInvalidCity ? '' : $cityId" :options="['' => 'Todos os municípios'] + $this->municipalities" searchable />
        @if ($hasFilters)
            <flux:button class="w-full" variant="outline" wire:click="clearFilters">Limpar filtros</flux:button>
        @endif
    </div>

    <flux:checkbox wire:model.live="myCourse" label="Somente locais com estágios iniciados no meu curso" />

    @php($locations = $this->locations)
    <x-loading-overlay target="search,cityId,myCourse,clearFilters,retrySearch,gotoPage,nextPage,previousPage,resetPage" label="Atualizando lista…" :dim-while-loading="true">
        @if ($this->searchFailed('locations'))
            <x-search-unavailable retry="retrySearch('locations')" />
        @elseif ($locations->isNotEmpty())
            <flux:table :paginate="$locations">
                <flux:table.columns>
                    <flux:table.column>Parte concedente<span class="sm:hidden"> / Município</span></flux:table.column>
                    <flux:table.column class="hidden sm:table-cell">Município / UF</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($locations as $location)
                        <flux:table.row :key="$location->id" class="group cursor-pointer transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-location-link]').click()">
                            <flux:table.cell class="whitespace-normal">
                                <a data-location-link href="{{ route('internship-locations.show', $location->id) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">{{ $location->name }}</a>
                                <flux:text size="sm" class="sm:hidden">{{ $location->municipality }} / {{ $location->state }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell class="hidden whitespace-normal sm:table-cell">{{ $location->municipality }} / {{ $location->state }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-12 text-center dark:border-zinc-700">
                <flux:icon.map-pin class="mx-auto mb-4 size-8 text-zinc-400" />
                @if ($this->municipalities === [])
                    <flux:heading level="2" size="lg">Nenhuma parte concedente disponível</flux:heading>
                    <flux:text class="mt-2">As partes concedentes ativas do seu campus aparecerão aqui.</flux:text>
                @elseif ($locations->total() > 0)
                    <flux:heading level="2" size="lg">Nenhuma parte concedente nesta página</flux:heading>
                    <flux:button class="mt-5" variant="ghost" wire:click="resetPage">Ir para a primeira página</flux:button>
                @else
                    <flux:heading level="2" size="lg">Nenhuma parte concedente encontrada</flux:heading>
                    <flux:text class="mt-2">Ajuste a busca por nome ou os filtros.</flux:text>
                    <flux:button class="mt-5" variant="ghost" wire:click="clearFilters">Limpar filtros</flux:button>
                @endif
            </div>
        @endif
    </x-loading-overlay>
</div>
