<?php

use App\Enums\AffiliationType;
use App\Models\User;
use App\Helpers\DigitsHelper;
use App\Support\UserManagementScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Usuários')] class extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public function boot(): void
    {
        Gate::authorize('viewAny', User::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<int, array{total: int, summary: string, active: int, inactive: int}>
     */
    #[Computed]
    public function affiliationSummaries(): array
    {
        return $this->users->getCollection()->mapWithKeys(function (User $user): array {
            $affiliations = $user->affiliations;
            $globalCount = $affiliations->where('type', AffiliationType::SystemAdministrator)->count();
            $campusCount = $affiliations->where('type', AffiliationType::InternshipOffice)->count();

            return [$user->id => [
                'total' => $affiliations->count(),
                'summary' => auth()->user()->can('viewAdministration', User::class) ? collect([
                    $globalCount > 0 ? $globalCount.' '.($globalCount === 1 ? 'global' : 'globais') : null,
                    $campusCount > 0 ? $campusCount.' vínculo'.($campusCount === 1 ? '' : 's').' de campus' : null,
                ])->filter()->implode(' · ') : $affiliations->map(fn ($affiliation) => $affiliation->type->label())->unique()->implode(' · '),
                'active' => $affiliations->whereNull('deactivated_at')->count(),
                'inactive' => $affiliations->whereNotNull('deactivated_at')->count(),
            ]];
        })->all();
    }

    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $search = mb_substr(trim($this->search), 0, 255);
        $scope = app(UserManagementScope::class);
        $visibleAffiliations = fn (Builder $query) => $scope->affiliations($query, auth()->user());
        $query = User::query()->whereHas('affiliations', $visibleAffiliations)
            ->with(['affiliations' => fn ($query) => $visibleAffiliations($query->getQuery())->with('campus')]);
        if ($search !== '' && ! str_contains($search, '@') && ! preg_match('/^[\d.\s-]+$/', $search)) {
            if (auth()->user()->cannot('viewAdministration', User::class)) {
                return $query->whereLike('name', '%'.addcslashes($search, '%_\\').'%')->orderBy('name')->orderBy('id')->paginate(15);
            }
            return User::search($search)->query(fn (Builder $builder) => $builder
                ->whereHas('affiliations', $visibleAffiliations)
                ->with(['affiliations' => fn ($query) => $visibleAffiliations($query->getQuery())->with('campus')]))
                ->paginate(15, 'page', $this->getPage());
        }
        if (str_contains($search, '@')) {
            $query->whereRaw('LOWER(email) = ?', [mb_strtolower($search)]);
        } elseif ($search !== '') {
            $query->where('cpf', DigitsHelper::only($search));
        }

        return $query->orderBy('name')->orderBy('id')->paginate(15);
    }
}; ?>

<div class="w-full space-y-8">
    @if (session('status'))
        <div x-data x-init="$nextTick(() => $flux.toast(@js(session('status')), { variant: 'success' }))"></div>
    @endif
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><flux:heading size="xl" level="1">Usuários</flux:heading><flux:text>{{ auth()->user()->can('viewAdministration', \App\Models\User::class) ? 'Contas com vínculos administrativos, incluindo desativados.' : 'Contas com vínculos no seu campus, incluindo desativados.' }}</flux:text></div>
        @can('create', \App\Models\User::class)
            <flux:button variant="primary" icon="plus" :href="route('users.create')" wire:navigate>Cadastrar usuário</flux:button>
        @endcan
    </div>
    <flux:input wire:model.live.debounce.300ms="search" label="Buscar usuário" placeholder="Nome, CPF completo ou e-mail de login completo" maxlength="255" type="search" />
    @php($users = $this->users)
    @php($affiliationSummaries = $this->affiliationSummaries)
    <x-loading-overlay target="search,gotoPage,nextPage,previousPage">
        <flux:table :paginate="$users">
            <flux:table.columns>
                <flux:table.column>Nome</flux:table.column><flux:table.column>CPF</flux:table.column><flux:table.column>E-mail de login</flux:table.column><flux:table.column>Vínculos</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($users as $user)
                    <flux:table.row :key="$user->id" class="group cursor-pointer transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-user-link]').click()">
                        <flux:table.cell><a data-user-link href="{{ route('users.show', $user) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">{{ $user->name }}</a></flux:table.cell>
                        <flux:table.cell>{{ $user->cpf }}</flux:table.cell>
                        <flux:table.cell>{{ $user->email }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:badge size="sm" color="zinc">{{ $affiliationSummaries[$user->id]['total'] }} {{ $affiliationSummaries[$user->id]['total'] === 1 ? 'vínculo' : 'vínculos' }}</flux:badge>
                                    <span class="text-sm text-zinc-700 dark:text-zinc-200">{{ $affiliationSummaries[$user->id]['summary'] }}</span>
                                </div>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $affiliationSummaries[$user->id]['active'] }} {{ $affiliationSummaries[$user->id]['active'] === 1 ? 'ativo' : 'ativos' }}
                                    · {{ $affiliationSummaries[$user->id]['inactive'] }} {{ $affiliationSummaries[$user->id]['inactive'] === 1 ? 'desativado' : 'desativados' }}
                                </p>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        @if ($users->isEmpty())
            <flux:text>Nenhum usuário encontrado.</flux:text>
        @endif
    </x-loading-overlay>
</div>
