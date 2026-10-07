<?php

use App\Models\Campus;
use App\Support\ActiveAffiliationContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Campi')] class extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $status = 'all';

    public function boot(): void
    {
        Gate::authorize('viewAdministration', Campus::class);
    }

    public function mount(): void
    {
        if (! in_array($this->status, ['all', 'active', 'inactive'], true)) {
            $this->status = 'all';
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
    }

    #[Computed]
    public function campuses(): LengthAwarePaginator
    {
        $affiliation = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'));
        abort_if($affiliation === null, 403);
        $search = mb_substr(trim($this->search), 0, 255);
        if ($search !== '') {
            $query = Campus::search($search);

            if ($this->status === 'active') {
                $query->where('deactivated_at', null);
            } elseif ($this->status === 'inactive') {
                $query->where('deactivated_at', '!=', null);
            }

            return $query
                ->query(fn (Builder $query) => $query->visibleTo($affiliation)->with('address.city'))
                ->paginate(15, 'page', $this->getPage());
        }

        return Campus::query()
            ->visibleTo($affiliation)
            ->with('address.city')
            ->when($this->status === 'active', fn (Builder $query) => $query->whereNull('deactivated_at'))
            ->when($this->status === 'inactive', fn (Builder $query) => $query->whereNotNull('deactivated_at'))
            ->orderBy('name')->orderBy('id')->paginate(15);
    }
}; ?>

<div class="w-full space-y-8">
    @if (session('status'))
        <div x-data x-init="$nextTick(() => $flux.toast(@js(session('status')), { variant: 'success' }))"></div>
    @endif
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Campi</flux:heading>
            <flux:text class="mt-2">Gerencie os dados e a disponibilidade dos campi da instituição.</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" :href="route('campuses.create')" wire:navigate>Cadastrar campus</flux:button>
    </div>

    <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
        <flux:input wire:model.live.debounce.300ms="search" label="Buscar campus" placeholder="Nome do campus" icon="magnifying-glass" maxlength="255" type="search" class="min-w-0 sm:flex-1" />
        <div class="sm:w-48">
            <x-select wire:model.live="status" label="Situação" :value="$status" :options="['all' => 'Todos', 'active' => 'Ativos', 'inactive' => 'Desativados']"
                :option-icons="['all' => 'building-office-2', 'active' => 'check-circle', 'inactive' => 'no-symbol']" />
        </div>
    </div>

    @php($campuses = $this->campuses)
    <x-loading-overlay target="search,status,clearFilters,gotoPage,nextPage,previousPage" label="Atualizando lista…" :dim-while-loading="true">
        @if ($campuses->isNotEmpty())
            <flux:table :paginate="$campuses" container:class="[&>ui-table-scroll-area]:max-h-[70vh]">
                <flux:table.columns sticky class="bg-white dark:bg-zinc-800">
                    <flux:table.column>Campus</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">Representante legal</flux:table.column>
                    <flux:table.column>Situação</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($campuses as $campus)
                        <flux:table.row :key="$campus->id" class="group cursor-pointer transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-campus-link]').click()">
                            <flux:table.cell class="whitespace-normal">
                                <a data-campus-link href="{{ route('campuses.show', $campus) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">{{ $campus->name }}</a>
                                <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $campus->address->city->name }} · {{ $campus->address->city->state->value }}</div>
                            </flux:table.cell>
                            <flux:table.cell class="hidden max-w-60 whitespace-normal md:table-cell">{{ $campus->legal_representative_name }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$campus->deactivated_at === null ? 'green' : 'zinc'">{{ $campus->deactivated_at === null ? 'Ativo' : 'Desativado' }}</flux:badge></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
                <flux:icon.building-office-2 class="mx-auto mb-4 size-8 text-zinc-400" />
                @if ($campuses->total() > 0)
                    <flux:heading size="lg">Nenhum campus nesta página</flux:heading>
                    <flux:text class="mt-2">Volte à primeira página para ver os resultados.</flux:text>
                    <flux:button class="mt-5" variant="ghost" wire:click="resetPage">Ir para a primeira página</flux:button>
                @elseif ($search !== '' || $status !== 'all')
                    <flux:heading size="lg">Nenhum campus encontrado</flux:heading>
                    <flux:text class="mt-2">Ajuste a busca ou a situação para encontrar um campus.</flux:text>
                    <flux:button class="mt-5" variant="ghost" wire:click="clearFilters">Limpar filtros</flux:button>
                @else
                    <flux:heading size="lg">Nenhum campus cadastrado</flux:heading>
                    <flux:text class="mt-2">Cadastre o primeiro campus para organizar os vínculos e os processos de estágio.</flux:text>
                    <flux:button class="mt-5" :href="route('campuses.create')" wire:navigate>Cadastrar campus</flux:button>
                @endif
            </div>
        @endif
    </x-loading-overlay>
</div>
