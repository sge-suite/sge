<?php

use App\Models\HolidayRecurrence;
use App\Support\HolidayAccess;
use App\Support\PageSize;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Recorrências municipais')] class extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search');
        $this->resetPage();
    }

    #[Locked]
    public bool $trash = false;

    public function mount(bool $trash = false): void
    {
        $this->trash = $trash;
    }

    #[Computed]
    public function hasTrash(): bool
    {
        return app(HolidayAccess::class)->recurrences(auth()->user(), true)->onlyTrashed()->exists();
    }

    #[Locked]
    public ?HolidayRecurrence $confirmationTarget = null;

    #[Locked]
    public string $confirmationOperation = 'delete';

    public bool $showConfirmation = false;

    public function openConfirmation(int $id, string $operation): void
    {
        abort_unless(in_array($operation, ['delete', 'restore'], true), 404);
        $target = app(HolidayAccess::class)->recurrences(auth()->user(), true)->findOrFail($id);
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
        Gate::authorize('viewAny', HolidayRecurrence::class);
    }

    #[Computed]
    public function recurrences(): LengthAwarePaginator
    {
        $search = mb_substr(trim($this->search), 0, 255);
        if ($search !== '') {
            $query = HolidayRecurrence::search($search);
            if ($this->trash) {
                $query->onlyTrashed();
            }

            return $query->orderBy('name')->orderBy('id')
                ->query(fn (Builder $query) => $query->with('city'))
                ->paginate(PageSize::PER_PAGE, 'page', $this->getPage());
        }

        return app(HolidayAccess::class)->recurrences(auth()->user(), $this->trash)
            ->when($this->trash, fn ($query) => $query->onlyTrashed())
            ->with('city')->orderBy('name')->orderBy('id')->paginate(PageSize::PER_PAGE);
    }
}; ?>

<div class="w-full space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $trash ? 'Lixeira de recorrências' : 'Recorrências municipais' }}</flux:heading>
            <flux:text class="mt-2">{{ $trash ? 'Recorrências excluídas que deixaram de gerar novas datas.' : 'Feriados municipais com data fixa anual. A geração prepara o ano atual e os dois seguintes.' }}</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            @cannot('create', HolidayRecurrence::class)<flux:badge>Somente leitura</flux:badge>@endcannot
            @if ($trash)
                <flux:button variant="outline" icon="arrow-left" :href="route('holiday-recurrences.index')" wire:navigate>Voltar</flux:button>
            @else
                @if ($this->hasTrash)<flux:button variant="outline" icon="trash" :href="route('holiday-recurrences.trash')" wire:navigate>Lixeira</flux:button>@endif
                @can('create', HolidayRecurrence::class)<flux:button variant="primary" icon="plus" :href="route('holiday-recurrences.create')" wire:navigate>Cadastrar recorrência</flux:button>@endcan
            @endif
        </div>
    </div>
    <x-holidays.navigation active-tab="recurrences" />
    @if (session('status'))
        <div hidden x-data x-init="$nextTick(() => $flux.toast(@js(session('status')), { variant: 'success' }))"></div>
    @endif
    @foreach (['recurrence', 'name'] as $field)<flux:error :name="$field" />@endforeach
    <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_9rem] sm:items-end">
        <flux:input wire:model.live.debounce.300ms="search" label="Buscar por nome" icon="magnifying-glass" maxlength="255" type="search" />
        <div>
            @if ($search !== '')
                <flux:button class="w-full" variant="outline" wire:click="clearFilters">Limpar filtros</flux:button>
            @else
                <div aria-hidden="true" class="h-10"></div>
            @endif
        </div>
    </div>
    @php($recurrences = $this->recurrences)
    <x-loading-overlay target="search,clearFilters,gotoPage,nextPage,previousPage,resetPage,openConfirmation" label="Atualizando lista…" :dim-while-loading="true">
        @if ($recurrences->isNotEmpty())
            <flux:table :paginate="$recurrences">
                <flux:table.columns>
                    <flux:table.column>Nome</flux:table.column>
                    <flux:table.column>Cidade / UF</flux:table.column>
                    <flux:table.column>Dia/mês</flux:table.column>
                    <flux:table.column>Vigência</flux:table.column>
                    @can('create', HolidayRecurrence::class)<flux:table.column>Ações</flux:table.column>@endcan
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($recurrences as $recurrence)
                        <flux:table.row :key="$recurrence->id">
                            <flux:table.cell class="whitespace-normal">{{ $recurrence->name }}</flux:table.cell>
                            <flux:table.cell class="whitespace-normal">{{ $recurrence->city->name }} / {{ $recurrence->city->state->value }}</flux:table.cell>
                            <flux:table.cell>{{ sprintf('%02d/%02d', $recurrence->day, $recurrence->month) }}</flux:table.cell>
                            <flux:table.cell class="whitespace-normal">{{ $recurrence->starts_year ?? 'Sem início definido' }} — {{ $recurrence->ends_year ?? 'Sem término definido' }}</flux:table.cell>
                            @can('create', HolidayRecurrence::class)
                                <flux:table.cell>
                                    <div class="flex flex-wrap items-center gap-2">
                                        @can('update', $recurrence)<flux:button size="sm" variant="outline" icon="pencil-square" :href="route('holiday-recurrences.edit', $recurrence)" wire:navigate>Editar</flux:button>@endcan
                                        @can('delete', $recurrence)<flux:button size="sm" variant="ghost" icon="trash" wire:click="openConfirmation({{ $recurrence->id }}, 'delete')">Excluir</flux:button>@endcan
                                        @can('restore', $recurrence)<flux:button size="sm" variant="outline" icon="arrow-path" wire:click="openConfirmation({{ $recurrence->id }}, 'restore')">Restaurar</flux:button>@endcan
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
                @if ($recurrences->total() > 0)
                    <flux:heading size="lg">Nenhuma recorrência nesta página</flux:heading>
                    <flux:button class="mt-5" variant="ghost" wire:click="resetPage">Ir para a primeira página</flux:button>
                @elseif ($search !== '')
                    <flux:heading size="lg">Nenhuma recorrência encontrada</flux:heading>
                    <flux:text class="mt-2">Ajuste a busca por nome para encontrar uma recorrência.</flux:text>
                    <flux:button class="mt-5" variant="ghost" wire:click="clearFilters">Limpar filtros</flux:button>
                @elseif ($trash)
                    <flux:heading size="lg">A lixeira está vazia</flux:heading>
                    <flux:text class="mt-2">As recorrências excluídas aparecerão aqui.</flux:text>
                @else
                    <flux:heading size="lg">Nenhuma recorrência cadastrada</flux:heading>
                    <flux:text class="mt-2">Os feriados municipais com data fixa anual aparecerão aqui.</flux:text>
                    @can('create', HolidayRecurrence::class)
                        <flux:button class="mt-5" :href="route('holiday-recurrences.create')" wire:navigate>Cadastrar recorrência</flux:button>
                    @endcan
                @endif
            </div>
        @endif
    </x-loading-overlay>
    <x-holidays.confirmation :target="$confirmationTarget" :operation="$confirmationOperation" kind="holiday-recurrences" />
</div>
