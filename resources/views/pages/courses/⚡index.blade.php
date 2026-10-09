<?php

use App\Models\Course;
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
    use WithPagination;

    #[Url(except: 'all')]
    public string $status = 'all';

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
        $this->reset('status');
        $this->resetPage();
    }

    #[Computed]
    public function courses(): LengthAwarePaginator
    {
        Gate::authorize('viewAny', Course::class);
        $affiliation = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'));

        return Course::query()->where('campus_id', $affiliation->campus_id)
            ->with(['primaryCoordinator.user', 'secondaryCoordinator.user'])
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
            <flux:heading size="xl" level="1">Cursos</flux:heading>
            <flux:text class="mt-2">Gerencie os cursos e a coordenação do seu campus.</flux:text>
        </div>
        @can('create', \App\Models\Course::class)
            <flux:button variant="primary" icon="plus" :href="route('courses.create')" wire:navigate>Cadastrar curso</flux:button>
        @else
            <flux:badge>Campus somente para leitura</flux:badge>
        @endcan
    </div>
    <div class="sm:w-48">
        <x-select wire:model.live="status" label="Situação" :value="$status" :options="['all' => 'Todos', 'active' => 'Ativos', 'inactive' => 'Desativados']" />
    </div>
    @php($courses = $this->courses)
    <x-loading-overlay target="status,clearFilters,gotoPage,nextPage,previousPage,resetPage" label="Atualizando lista…" :dim-while-loading="true">
        @if ($courses->isNotEmpty())
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
                            <flux:table.cell class="whitespace-normal"><a data-course-link href="{{ route('courses.show', $course) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">{{ $course->name }}</a></flux:table.cell>
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
                @elseif ($status !== 'all')
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
