<?php

use App\Models\InternshipType;
use App\Support\ActiveAffiliationContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Tipos de estágio')] class extends Component
{
    use WithPagination;

    #[Url(except: 'all')]
    public string $status = 'all';

    public function boot(): void
    {
        Gate::authorize('viewAny', InternshipType::class);
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
        $this->reset('status');
        $this->resetPage();
    }

    #[Computed]
    public function internshipTypes(): LengthAwarePaginator
    {
        Gate::authorize('viewAny', InternshipType::class);
        $affiliation = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'));

        return InternshipType::query()->whereHas('course', fn (Builder $query) => $query->where('campus_id', $affiliation->campus_id))
            ->with('course')
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
            <flux:heading size="xl" level="1">Tipos de estágio</flux:heading>
            <flux:text class="mt-2">Gerencie as regras dos tipos de estágio dos cursos do seu campus.</flux:text>
        </div>
        @can('create', \App\Models\InternshipType::class)
            <flux:button variant="primary" icon="plus" :href="route('internship-types.create')" wire:navigate>Cadastrar tipo de estágio</flux:button>
        @else
            <flux:badge>Campus somente para leitura</flux:badge>
        @endcan
    </div>
    <div class="sm:w-48">
        <x-select wire:model.live="status" label="Situação" :value="$status" :options="['all' => 'Todos', 'active' => 'Ativos', 'inactive' => 'Desativados']" />
    </div>
    @php($internshipTypes = $this->internshipTypes)
    <x-loading-overlay target="status,clearFilters,gotoPage,nextPage,previousPage,resetPage" label="Atualizando lista…" :dim-while-loading="true">
        @if ($internshipTypes->isNotEmpty())
            <flux:table :paginate="$internshipTypes" container:class="[&>ui-table-scroll-area]:max-h-[70vh]">
                <flux:table.columns sticky class="bg-white dark:bg-zinc-800">
                    <flux:table.column>Tipo de estágio</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">Curso</flux:table.column>
                    <flux:table.column class="hidden lg:table-cell">Carga horária</flux:table.column>
                    <flux:table.column>Situação</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($internshipTypes as $internshipType)
                        <flux:table.row :key="$internshipType->id" class="group cursor-pointer transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-internship-type-link]').click()">
                            <flux:table.cell class="whitespace-normal"><a data-internship-type-link href="{{ route('internship-types.show', $internshipType) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">{{ $internshipType->name }}</a></flux:table.cell>
                            <flux:table.cell class="hidden whitespace-normal md:table-cell">{{ $internshipType->course->name }}</flux:table.cell>
                            <flux:table.cell class="hidden whitespace-normal lg:table-cell">{{ $internshipType->required_hours }} horas</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$internshipType->deactivated_at === null ? 'green' : 'zinc'">{{ $internshipType->deactivated_at === null ? 'Ativo' : 'Desativado' }}</flux:badge></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
                <flux:icon.academic-cap class="mx-auto mb-4 size-8 text-zinc-400" />
                @if ($internshipTypes->total() > 0)
                    <flux:heading size="lg">Nenhum tipo de estágio nesta página</flux:heading>
                    <flux:button class="mt-5" variant="ghost" wire:click="resetPage">Ir para a primeira página</flux:button>
                @elseif ($status !== 'all')
                    <flux:heading size="lg">Nenhum tipo de estágio encontrado</flux:heading>
                    <flux:text class="mt-2">Ajuste a situação para encontrar um tipo de estágio.</flux:text>
                    <flux:button class="mt-5" variant="ghost" wire:click="clearFilters">Limpar filtros</flux:button>
                @else
                    <flux:heading size="lg">Nenhum tipo de estágio cadastrado</flux:heading>
                    <flux:text class="mt-2">Os tipos de estágio do seu campus aparecerão aqui.</flux:text>
                    @can('create', \App\Models\InternshipType::class)
                        <flux:button class="mt-5" :href="route('internship-types.create')" wire:navigate>Cadastrar tipo de estágio</flux:button>
                    @endcan
                @endif
            </div>
        @endif
    </x-loading-overlay>
</div>
