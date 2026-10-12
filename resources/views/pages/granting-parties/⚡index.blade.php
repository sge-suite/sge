<?php

use App\Helpers\DigitsHelper;
use App\Models\GrantingParty;
use App\Support\PageSize;
use App\Support\ActiveAffiliationContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    public function updatedSearch(): void
    {
        $this->resetPage();
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
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'search');
        $this->resetPage();
    }

    #[Computed]
    public function grantingParties(): LengthAwarePaginator
    {
        Gate::authorize('viewAny', GrantingParty::class);
        $affiliation = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'));

        return GrantingParty::query()->where('campus_id', $affiliation->campus_id)
            ->with('address.city')
            ->when($this->status === 'active', fn (Builder $query) => $query->whereNull('deactivated_at'))
            ->when($this->status === 'inactive', fn (Builder $query) => $query->whereNotNull('deactivated_at'))
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $term = mb_substr(trim($this->search), 0, 255);
                $query->where(fn (Builder $query) => $query->whereLike('name', '%'.addcslashes($term, '%_').'%', caseSensitive: false)->orWhere('document_number', DigitsHelper::only($term)));
            })->orderBy('name')->orderBy('id')->paginate(PageSize::PER_PAGE);
    }
}; ?>

<div class="w-full space-y-8">
    @if (session('status'))
        <div x-data x-init="$nextTick(() => $flux.toast(@js(session('status')), { variant: 'success' }))"></div>
    @endif
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Partes concedentes</flux:heading>
            <flux:text class="mt-2">Gerencie as partes concedentes do seu campus.</flux:text>
        </div>
        @can('create', \App\Models\GrantingParty::class)
            <flux:button variant="primary" icon="plus" :href="route('granting-parties.create')" wire:navigate>Cadastrar parte concedente</flux:button>
        @else
            <flux:badge>Campus somente para leitura</flux:badge>
        @endcan
    </div>
    <div class="flex items-end gap-4">
        <div class="min-w-0 flex-1">
            <flux:input wire:model.live.debounce.300ms="search" label="Buscar por nome ou CPF/CNPJ completo" icon="magnifying-glass" />
        </div>
        <div class="w-40 shrink-0 sm:w-48">
            <x-select wire:model.live="status" label="Situação" :value="$status" :options="['all' => 'Todos', 'active' => 'Ativos', 'inactive' => 'Desativados']" />
        </div>
    </div>
    @php($grantingParties = $this->grantingParties)
    <x-loading-overlay target="search,status,clearFilters,gotoPage,nextPage,previousPage,resetPage" label="Atualizando lista…" :dim-while-loading="true">
        @if ($grantingParties->isNotEmpty())
            <flux:table :paginate="$grantingParties" container:class="[&>ui-table-scroll-area]:max-h-[70vh]">
                <flux:table.columns sticky class="bg-white dark:bg-zinc-800">
                    <flux:table.column>Parte concedente</flux:table.column>
                    <flux:table.column>Documento</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">Cidade / UF</flux:table.column>
                    <flux:table.column>Situação</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($grantingParties as $grantingParty)
                        <flux:table.row :key="$grantingParty->id" class="group cursor-pointer transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-grantingParty-link]').click()">
                            <flux:table.cell class="whitespace-normal"><a data-grantingParty-link href="{{ route('granting-parties.show', $grantingParty) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">{{ $grantingParty->name }}</a></flux:table.cell>
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
                @elseif ($status !== 'all' || $search !== '')
                    <flux:heading size="lg">Nenhuma parte concedente encontrada</flux:heading>
                    <flux:text class="mt-2">Ajuste a busca ou a situação para encontrar uma parte concedente.</flux:text>
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
