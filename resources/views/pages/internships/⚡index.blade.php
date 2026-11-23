<?php

use App\Enums\InternshipStatus;
use App\Enums\AffiliationType;
use App\Models\Course;
use App\Models\Affiliation;
use App\Models\GrantingParty;
use App\Models\Internship;
use App\Models\User;
use App\Support\InternshipListing;
use App\Support\PageSize;
use App\Support\StudentInternshipContext;
use App\Support\SelectOptionSource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\MessageBag;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Estágios')] class extends Component
{
    use \App\Concerns\HandlesSearchFailures;
    use WithPagination;
    use \App\Concerns\SearchesSelectOptions;

    protected function selectSearchSource(string $source): SelectOptionSource
    {
        $owner = app(StudentInternshipContext::class)->selected(Auth::user());
        $visible = Internship::query()->visibleToAffiliation($owner);

        return match ($source) {
            'courses' => new SelectOptionSource(Course::query()->where('campus_id', $owner->campus_id)
                ->when(in_array($owner->type, [AffiliationType::Coordinator, AffiliationType::Advisor], true),
                    fn ($query) => $query->whereIn('id', (clone $visible)->select('course_id')))
                ->orderBy('name')->orderBy('id'),
                'campus_id = '.(int) $owner->campus_id, fn (Course $course): string => $course->name),
            'advisors' => new SelectOptionSource(Affiliation::query()
                ->whereIn('id', (clone $visible)->select('advisor_affiliation_id'))->with('user')
                ->orderBy(User::query()->select('name')->whereColumn('users.id', 'affiliations.user_id'))->orderBy('id'),
                $owner->type === AffiliationType::Advisor ? 'id = '.(int) $owner->id : '',
                fn (Affiliation $advisor): string => $advisor->user->name),
            'grantingParties' => new SelectOptionSource(GrantingParty::query()->forCampus((int) $owner->campus_id)
                ->when(in_array($owner->type, [AffiliationType::Coordinator, AffiliationType::Advisor], true),
                    fn (Builder $query) => $query->whereIn('id', (clone $visible)->select('granting_party_id')))
                ->with('address.city')->orderBy('name')->orderBy('id'),
                'campus_id = '.(int) $owner->campus_id,
                fn (GrantingParty $party): string => $party->name.' · '.$party->address->city->name.'/'.$party->address->city->state->value.' · Unidade nº '.$party->id,
                attributes: ['name']),
            default => abort(404),
        };
    }

    #[Locked] public int $affiliationId;
    #[Url(except: '')] public string $search = '';
    #[Url(except: 'all')] public string $status = 'all';
    #[Url(except: '', as: 'course')] public string $courseId = '';
    #[Url(except: '', as: 'advisor')] public string $advisorId = '';
    #[Url(except: '', as: 'granting_party')] public string $grantingPartyId = '';
    #[Url(except: false, as: 'pending_cancellations')] public bool $pendingCancellations = false;

    #[Url(except: '', as: 'projected_end_from')] public string $projectedEndFrom = '';
    #[Url(except: '', as: 'projected_end_to')] public string $projectedEndTo = '';
    #[Url(except: 'updated')] public string $sort = 'updated';

    protected function searchRetryProperties(): array
    {
        return ['internships' => ['internships']];
    }

    public function boot(): void
    {
        Gate::authorize('viewAny', Internship::class);
    }

    public function mount(StudentInternshipContext $context): void
    {
        $this->affiliationId = $context->selected(Auth::user())->id;
    }

    public function hydrate(StudentInternshipContext $context): void
    {
        abort_unless($context->selected(Auth::user())?->id === $this->affiliationId, 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'courseId', 'advisorId', 'grantingPartyId', 'pendingCancellations',
            'projectedEndFrom', 'projectedEndTo', 'sort'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function accessDescription(): string
    {
        return match (app(StudentInternshipContext::class)->selected(Auth::user())->type) {
            AffiliationType::Coordinator => 'Acompanhe os estágios dos cursos que você coordena e dos seus orientandos.',
            AffiliationType::Advisor => 'Acompanhe os estágios em que você é o orientador responsável.',
            AffiliationType::TeachingDirection => 'Consulte a situação e os períodos dos estágios do seu campus.',
            default => 'Acompanhe os estágios do campus, suas pausas e a previsão de término.',
        };
    }

    /** @return array<string, string|int> */
    private function filters(): array
    {
        return array_filter([
            'search' => mb_substr(trim($this->search), 0, 255),
            'status' => $this->status === 'all' ? '' : $this->status,
            'course' => $this->courseId,
            'advisor' => $this->advisorId,
            'granting_party' => $this->grantingPartyId,
            'pending_cancellations' => (int) $this->pendingCancellations,
            'projected_end_from' => $this->projectedEndFrom,
            'projected_end_to' => $this->projectedEndTo,
            'sort' => $this->sort === 'updated' ? '' : $this->sort,
        ], fn (string|int $value): bool => $value !== '' && $value !== 0);
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return $this->filters() !== [];
    }

    #[Computed]
    public function filterErrors(): MessageBag
    {
        return app(InternshipListing::class)->validator($this->filters())->errors();
    }

    #[Computed]
    public function csvUrl(): ?string
    {
        if (! Gate::allows('exportCsv', Internship::class) || $this->filterErrors->isNotEmpty() || $this->searchFailed('internships')) {
            return null;
        }

        return route('internships.export', ['affiliation' => $this->affiliationId] + $this->filters());
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'courseId', 'advisorId', 'grantingPartyId', 'pendingCancellations',
            'projectedEndFrom', 'projectedEndTo', 'sort');
        $this->resetPage();
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusOptions(): array
    {
        return ['all' => 'Todos'] + InternshipStatus::options();
    }

    /** @return array<int|string, string> */
    #[Computed]
    public function courseOptions(): array
    {
        return ['' => 'Todos os cursos'] + $this->selectedSelectOptions('courses', $this->courseId);
    }

    /** @return array<int|string, string> */
    #[Computed]
    public function advisorOptions(): array
    {
        return ['' => 'Todos os orientadores'] + $this->selectedSelectOptions('advisors', $this->advisorId);
    }

    /** @return array<int|string, string> */
    #[Computed]
    public function grantingPartyOptions(): array
    {
        return ['' => 'Todas as concedentes'] + $this->selectedSelectOptions('grantingParties', $this->grantingPartyId);
    }

    /** @return LengthAwarePaginator<int, Internship> */
    #[Computed]
    public function internships(): LengthAwarePaginator
    {
        Gate::authorize('viewAny', Internship::class);
        $owner = app(StudentInternshipContext::class)->selected(Auth::user());
        if ($this->filterErrors->isNotEmpty()) {
            return $this->unavailableSearchPage();
        }

        return $this->searchWithRecovery('internships', fn () => app(InternshipListing::class)
            ->query($owner, $this->filters())
            ->with(['course', 'advisorAffiliation.user'])->paginate(PageSize::PER_PAGE),
            fn () => $this->unavailableSearchPage());
    }
}; ?>

<div class="w-full space-y-8">
    @php($affiliationType = app(StudentInternshipContext::class)->selected(auth()->user())->type)
    <div>
        <flux:heading level="1" size="xl">Estágios</flux:heading>
        <flux:text class="mt-2">{{ $this->accessDescription }}</flux:text>
    </div>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_14rem_14rem] xl:items-end">
        <flux:input wire:model.live.debounce.300ms="search" :label="$affiliationType === AffiliationType::InternshipOffice ? 'Buscar por discente, matrícula, protocolo do documento ou número' : 'Buscar por discente, matrícula ou número'" icon="magnifying-glass" maxlength="255" type="search" />
        <div class="min-w-0"><x-select search-source="courses" wire:model.live="courseId" label="Curso" :value="$courseId" :options="$this->courseOptions" searchable /></div>
        <div class="min-w-0"><x-select wire:model.live="status" label="Situação" :value="$status" :options="$this->statusOptions" /></div>
        @if ($affiliationType !== AffiliationType::Advisor)
            <div class="min-w-0"><x-select search-source="advisors" wire:model.live="advisorId" label="Orientador" :value="$advisorId" :options="$this->advisorOptions" searchable /></div>
        @endif
        <div class="min-w-0"><x-select search-source="grantingParties" wire:model.live="grantingPartyId" label="Parte concedente" :value="$grantingPartyId" :options="$this->grantingPartyOptions" searchable /></div>
        <div class="min-w-0"><x-select wire:model.live="sort" label="Ordenar por" :value="$sort" :options="InternshipListing::ORDERS" /></div>
        <flux:field>
            <flux:label>Término previsto de</flux:label>
            <flux:input wire:model.live="projectedEndFrom" type="date" />
            @if ($message = $this->filterErrors->first('projected_end_from'))
                <flux:text role="alert" class="mt-2 text-red-600 dark:text-red-400">{{ $message }}</flux:text>
            @endif
        </flux:field>
        <flux:field>
            <flux:label>Término previsto até</flux:label>
            <flux:input wire:model.live="projectedEndTo" type="date" />
            @if ($message = $this->filterErrors->first('projected_end_to'))
                <flux:text role="alert" class="mt-2 text-red-600 dark:text-red-400">{{ $message }}</flux:text>
            @endif
        </flux:field>
        @if ($this->hasFilters)
            <flux:button variant="outline" wire:click="clearFilters">Limpar filtros</flux:button>
        @endif
    </div>
    @php($internships = $this->internships)
    @if (in_array($affiliationType, [AffiliationType::InternshipOffice, AffiliationType::Coordinator, AffiliationType::Advisor], true))
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                @if ($affiliationType === AffiliationType::InternshipOffice)
                    <flux:checkbox wire:model.live="pendingCancellations" label="Somente com pedido de cancelamento pendente" />
                @endif
            </div>
            @can('exportCsv', Internship::class)
                @if ($this->csvUrl !== null)
                    <flux:button :href="$this->csvUrl" variant="outline" icon="arrow-down-tray" wire:loading.remove wire:target="search,courseId,advisorId,grantingPartyId,status,projectedEndFrom,projectedEndTo,sort,clearFilters">
                        Exportar CSV
                    </flux:button>
                @endif
            @endcan
        </div>
    @endif
    <x-loading-overlay target="retrySearch,search,courseId,advisorId,grantingPartyId,status,pendingCancellations,projectedEndFrom,projectedEndTo,sort,clearFilters,gotoPage,nextPage,previousPage,resetPage" label="Atualizando lista…" :dim-while-loading="true">
        @if ($this->searchFailed('internships'))
            <x-search-unavailable retry="retrySearch('internships')" />
        @elseif ($this->filterErrors->hasAny(['projected_end_from', 'projected_end_to']))
            <flux:text>Corrija o período para consultar os estágios.</flux:text>
        @elseif ($this->filterErrors->isNotEmpty())
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.text>{{ $this->filterErrors->first() }}</flux:callout.text>
            </flux:callout>
        @elseif ($internships->isNotEmpty())
            <x-academic-internships-table :internships="$internships" :paginated="true" :is-office="$affiliationType === AffiliationType::InternshipOffice" />
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
                <flux:icon.briefcase class="mx-auto mb-4 size-8 text-zinc-400" />
                @if ($internships->total() > 0)
                    <flux:heading size="lg">Nenhum estágio nesta página</flux:heading>
                    <flux:button class="mt-5" variant="ghost" wire:click="resetPage">Ir para a primeira página</flux:button>
                @elseif ($this->hasFilters)
                    <flux:heading size="lg">Nenhum estágio encontrado</flux:heading>
                    <flux:text class="mt-2">Ajuste a busca ou os filtros para encontrar um estágio.</flux:text>
                    <flux:button class="mt-5" variant="ghost" wire:click="clearFilters">Limpar filtros</flux:button>
                @else
                    <flux:heading size="lg">Nenhum estágio cadastrado</flux:heading>
                    <flux:text class="mt-2">Os estágios disponíveis para o vínculo selecionado aparecerão aqui.</flux:text>
                @endif
            </div>
        @endif
    </x-loading-overlay>
</div>
