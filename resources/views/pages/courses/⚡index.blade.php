<?php

use App\Models\Course;
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

new #[Title('Cursos')] class extends Component
{
    use \App\Concerns\HandlesSearchFailures;
    use WithPagination;

    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: '')]
    public string $search = '';

    protected function searchRetryProperties(): array
    {
        return ['courses' => ['courses']];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function boot(): void
    {
        Gate::authorize('viewAny', Course::class);
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
    public function courses(): LengthAwarePaginator
    {
        Gate::authorize('viewAny', Course::class);
        $affiliation = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'));

        $search = mb_substr(trim($this->search), 0, 255);
        $query = Course::query()->where('campus_id', $affiliation->campus_id)
            ->with(['primaryCoordinator.user', 'secondaryCoordinator.user'])
            ->when($this->status === 'active', fn (Builder $query) => $query->whereNull('deactivated_at'))
            ->when($this->status === 'inactive', fn (Builder $query) => $query->whereNotNull('deactivated_at'));

        if ($search !== '') {
            return $this->searchWithRecovery('courses', fn () => Course::search($search)->where('campus_id', (int) $affiliation->campus_id)
                ->when($this->status !== 'all', fn ($searchQuery) => $searchQuery->where('is_active', (int) ($this->status === 'active')))
                ->query(fn (Builder $builder) => $builder->mergeConstraintsFrom($query)->with($query->getEagerLoads()))
                ->orderBy('name')->orderBy('id')->paginate(PageSize::PER_PAGE, 'page', $this->getPage()),
                fn () => $this->unavailableSearchPage());
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
            <flux:heading size="xl" level="1">Cursos</flux:heading>
            <flux:text class="mt-2">Gerencie os cursos e a coordenação do seu campus.</flux:text>
        </div>
        @can('create', \App\Models\Course::class)
            <flux:button variant="primary" icon="plus" :href="route('courses.create')" wire:navigate>Cadastrar curso</flux:button>
        @else
            <flux:badge>Campus somente para leitura</flux:badge>
        @endcan
    </div>
    <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_12rem_9rem] sm:items-end">
        <flux:input wire:model.live.debounce.300ms="search" label="Buscar por nome" icon="magnifying-glass" maxlength="255" type="search" />
        <div>
            <x-select wire:model.live="status" label="Situação" :value="$status" :options="['all' => 'Todos', 'active' => 'Ativos', 'inactive' => 'Desativados']" />
        </div>
        <div>
            @if ($status !== 'all' || $search !== '')
                <flux:button class="w-full" variant="outline" wire:click="clearFilters">Limpar filtros</flux:button>
            @else
                <div aria-hidden="true" class="h-10"></div>
            @endif
        </div>
    </div>
    @php($courses = $this->courses)
    <x-loading-overlay target="retrySearch,search,status,clearFilters,gotoPage,nextPage,previousPage,resetPage" label="Atualizando lista…" :dim-while-loading="true">
        @if ($this->searchFailed('courses'))
            <x-search-unavailable retry="retrySearch('courses')" />
        @elseif ($courses->isNotEmpty())
            <flux:table :paginate="$courses" container:class="[&>ui-table-scroll-area]:max-h-[70vh]">
                <flux:table.columns sticky class="bg-white dark:bg-zinc-800">
                    <flux:table.column>Curso</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">Coordenador principal</flux:table.column>
                    <flux:table.column class="hidden lg:table-cell">Coordenador secundário</flux:table.column>
                    <flux:table.column>Situação</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($courses as $course)
                        <flux:table.row :key="$course->id" class="group cursor-pointer transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-course-link]').click()">
                            <flux:table.cell class="whitespace-normal"><a data-course-link href="{{ route('courses.show', $course) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">{{ $course->name }}</a><flux:text size="sm" class="md:hidden">Coordenação titular: {{ $course->primaryCoordinator?->user?->name ?? 'Não informada' }}</flux:text><flux:text size="sm" class="lg:hidden">Coordenação substituta: {{ $course->secondaryCoordinator?->user?->name ?? 'Não informada' }}</flux:text></flux:table.cell>
                            <flux:table.cell class="hidden whitespace-normal md:table-cell">{{ $course->primaryCoordinator?->user?->name ?? 'Não informado' }}</flux:table.cell>
                            <flux:table.cell class="hidden whitespace-normal lg:table-cell">{{ $course->secondaryCoordinator?->user?->name ?? 'Não informado' }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$course->deactivated_at === null ? 'green' : 'zinc'">{{ $course->deactivated_at === null ? 'Ativo' : 'Desativado' }}</flux:badge></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
                <flux:icon.academic-cap class="mx-auto mb-4 size-8 text-zinc-400" />
                @if ($courses->total() > 0)
                    <flux:heading size="lg">Nenhum curso nesta página</flux:heading>
                    <flux:button class="mt-5" variant="ghost" wire:click="resetPage">Ir para a primeira página</flux:button>
                @elseif ($status !== 'all' || $search !== '')
                    <flux:heading size="lg">Nenhum curso encontrado</flux:heading>
                    <flux:text class="mt-2">Ajuste a situação para encontrar um curso.</flux:text>
                    <flux:button class="mt-5" variant="ghost" wire:click="clearFilters">Limpar filtros</flux:button>
                @else
                    <flux:heading size="lg">Nenhum curso cadastrado</flux:heading>
                    <flux:text class="mt-2">Os cursos do seu campus aparecerão aqui.</flux:text>
                    @can('create', \App\Models\Course::class)
                        <flux:button class="mt-5" :href="route('courses.create')" wire:navigate>Cadastrar curso</flux:button>
                    @endcan
                @endif
            </div>
        @endif
    </x-loading-overlay>
</div>
