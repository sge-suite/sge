<?php

use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\User;
use App\Support\AdministrativeActivityPresenter;
use App\Support\ActivityAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

new #[Title('Auditoria')] class extends Component
{
    use WithPagination;

    #[Url(except: 'all')]
    public string $entity = 'all';

    #[Url(except: 'all')]
    public string $event = 'all';

    public function boot(): void
    {
        Gate::authorize('viewAny', Activity::class);
    }

    public function mount(): void
    {
        $this->normalizeFilters();
    }

    public function updatedEntity(): void
    {
        $this->normalizeFilters();
        $this->resetPage();
    }

    public function updatedEvent(): void
    {
        $this->normalizeFilters();
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->entity = 'all';
        $this->event = 'all';
        $this->resetPage();
    }

    private function normalizeFilters(): void
    {
        if (! in_array($this->entity, ['all', 'campus', 'user', 'affiliation'], true)) {
            $this->entity = 'all';
        }

        if (! in_array($this->event, ['all', 'created', 'updated', 'deleted', 'restored', 'password_changed'], true)) {
            $this->event = 'all';
        }
    }

    #[Computed]
    public function activities(): LengthAwarePaginator
    {
        $query = app(ActivityAccess::class)->forCurrentContext(auth()->user(), app('session.store'));
        abort_if($query === null, 403);
        $types = ['campus' => new Campus, 'user' => new User, 'affiliation' => new Affiliation];

        if (isset($types[$this->entity])) {
            $query->where('subject_type', $types[$this->entity]->getMorphClass());
        }

        if (in_array($this->event, ['created', 'updated', 'deleted', 'restored', 'password_changed'], true)) {
            $query->where('event', $this->event);
        }

        $activities = $query->with([
            'subject' => fn (MorphTo $relation) => $relation
                ->constrain([Campus::class => fn (Builder $query) => $query->withTrashed()])
                ->morphWith([Affiliation::class => ['user']]),
            'causer' => fn (MorphTo $relation) => $relation->morphWith([Affiliation::class => ['user']]),
        ])->orderByDesc('created_at')->orderByDesc('id')->paginate(15);
        $presenter = app(AdministrativeActivityPresenter::class);

        return $activities->through(fn (Activity $activity): array => [
            'id' => $activity->id,
            ...$presenter->summary($activity),
        ]);
    }
}; ?>

<div class="w-full space-y-8">
    <div>
        <flux:heading size="xl" level="1">Auditoria</flux:heading>
        <flux:text>Histórico de campi, contas com vínculos administrativos e vínculos de Administrador do Sistema e Administrador do Campus.</flux:text>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full sm:max-w-xs">
            <x-select wire:model.live="entity" label="Registro" :value="$entity" :options="[
                'all' => 'Todos os registros administrativos',
                'campus' => 'Campi',
                'user' => 'Contas de usuários',
                'affiliation' => 'Vínculos administrativos',
            ]" />
        </div>
        <div class="w-full sm:max-w-xs">
            <x-select wire:model.live="event" label="Ação" :value="$event" :options="[
                'all' => 'Todas as ações',
                'created' => 'Criação',
                'updated' => 'Alteração',
                'deleted' => 'Exclusão',
                'restored' => 'Restauração',
                'password_changed' => 'Senha alterada',
            ]" />
        </div>
        @if ($entity !== 'all' || $event !== 'all')
            <flux:button wire:click="clearFilters">Limpar filtros</flux:button>
        @endif
    </div>

    @php($activities = $this->activities)
    <x-loading-overlay target="entity,event,clearFilters,gotoPage,nextPage,previousPage" label="Atualizando histórico…">
        <x-audit.activity-table :activities="$activities" />
        @if ($activities->isEmpty())
            <flux:text>Nenhum registro de auditoria encontrado nesta página.</flux:text>
        @endif
    </x-loading-overlay>
</div>
