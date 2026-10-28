<?php

use App\Enums\InternshipStatus;
use App\Enums\InternshipCancellationRequestStatus;
use App\Enums\AffiliationType;
use App\Models\Course;
use App\Models\Affiliation;
use App\Models\Internship;
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

new #[Title('Estágios')] class extends Component
{
    use WithPagination;

    #[Locked] public int $affiliationId;
    #[Url(except: '')] public string $search = '';
    #[Url(except: 'all')] public string $status = 'all';
    #[Url(except: '', as: 'course')] public string $courseId = '';
    #[Url(except: '', as: 'advisor')] public string $advisorId = '';
    #[Url(except: false, as: 'pending_cancellations')] public bool $pendingCancellations = false;

    public function boot(): void
    {
        Gate::authorize('viewAny', Internship::class);
    }

    public function mount(StudentInternshipContext $context): void
    {
        $this->affiliationId = $context->selected(Auth::user())->id;
        $this->normalizeFilters();
    }

    public function hydrate(StudentInternshipContext $context): void
    {
        abort_unless($context->selected(Auth::user())?->id === $this->affiliationId, 403);
    }

    public function updatedPendingCancellations(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->normalizeFilters();
        $this->resetPage();
    }

    public function updatedCourseId(): void
    {
        $this->normalizeFilters();
        $this->resetPage();
    }

    public function updatedAdvisorId(): void
    {
        $this->normalizeFilters();
        $this->resetPage();
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

    private function normalizeFilters(): void
    {
        if (! array_key_exists($this->status, $this->statusOptions)) {
            $this->status = 'all';
        }
        if (! array_key_exists($this->advisorId, $this->advisorOptions)) {
            $this->advisorId = '';
        }
        if (! array_key_exists($this->courseId, $this->courseOptions)) {
            $this->courseId = '';
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'courseId', 'advisorId', 'pendingCancellations');
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
        $owner = app(StudentInternshipContext::class)->selected(Auth::user());

        return ['' => 'Todos os cursos'] + Course::query()->where('campus_id', $owner->campus_id)
            ->when(in_array($owner->type, [AffiliationType::Coordinator, AffiliationType::Advisor], true), fn ($query) => $query
                ->whereIn('id', Internship::query()->visibleToAffiliation($owner)->select('course_id')))
            ->orderBy('name')->orderBy('id')->pluck('name', 'id')->all();
    }

    /** @return array<int|string, string> */
    #[Computed]
    public function advisorOptions(): array
    {
        $owner = app(StudentInternshipContext::class)->selected(Auth::user());

        return ['' => 'Todos os orientadores'] + Affiliation::query()
            ->whereIn('id', Internship::query()->visibleToAffiliation($owner)->select('advisor_affiliation_id'))
            ->with('user')->get()->sortBy(fn ($advisor) => $advisor->user->name)
            ->mapWithKeys(fn ($advisor) => [$advisor->id => $advisor->user->name])->all();
    }

    /** @return LengthAwarePaginator<int, Internship> */
    #[Computed]
    public function internships(): LengthAwarePaginator
    {
        Gate::authorize('viewAny', Internship::class);
        $owner = app(StudentInternshipContext::class)->selected(Auth::user());
        $search = mb_substr(trim($this->search), 0, 255);

        $query = Internship::query()->visibleToAffiliation($owner)
            ->when($owner->type === AffiliationType::InternshipOffice, function (Builder $query): void {
                $pending = fn (Builder $requests) => $requests->whereIn('status', [InternshipCancellationRequestStatus::Submitted, InternshipCancellationRequestStatus::UnderReview]);
                $query->withCount(['cancellationRequests as pending_cancellations_count' => $pending]);
                if ($this->pendingCancellations) {
                    $query->whereHas('cancellationRequests', $pending);
                }
            })
            ->when($this->status !== 'all', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->advisorId !== '', fn (Builder $query) => $query->where('advisor_affiliation_id', $this->advisorId))
            ->when($this->courseId !== '', fn (Builder $query) => $query->where('course_id', $this->courseId))
            ->with(['course', 'advisorAffiliation.user']);

        if ($search !== '') {
            return Internship::searchVisibleToAffiliation($search, $owner)
                ->when($this->status !== 'all', fn ($searchQuery) => $searchQuery->where('status', InternshipStatus::tryFrom($this->status)?->value ?? 'invalid'))
                ->when($this->advisorId !== '', fn ($searchQuery) => $searchQuery->where('advisor_affiliation_id', (int) $this->advisorId))
                ->when($this->courseId !== '', fn ($searchQuery) => $searchQuery->where('course_id', (int) $this->courseId))
                ->when($owner->type === AffiliationType::InternshipOffice && $this->pendingCancellations, fn ($searchQuery) => $searchQuery->where('has_pending_cancellation', 1))
                ->query(function (Builder $builder) use ($query, $owner): void {
                    $builder->mergeConstraintsFrom($query)->with($query->getEagerLoads());
                    if ($owner->type === AffiliationType::InternshipOffice) {
                        $builder->withCount(['cancellationRequests as pending_cancellations_count' => fn (Builder $requests) => $requests
                            ->whereIn('status', [InternshipCancellationRequestStatus::Submitted, InternshipCancellationRequestStatus::UnderReview])]);
                    }
                })
                ->orderBy('updated_at', 'desc')->orderBy('id', 'desc')->paginate(PageSize::PER_PAGE, 'page', $this->getPage());
        }

        return $query->latest('updated_at')->latest('id')->paginate(PageSize::PER_PAGE);
    }
}; ?>

<div class="w-full space-y-8">
    <div>
        <flux:heading level="1" size="xl">Estágios</flux:heading>
        <flux:text class="mt-2">{{ $this->accessDescription }}</flux:text>
    </div>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_14rem_14rem] xl:items-end">
        <flux:input wire:model.live.debounce.300ms="search" :label="app(StudentInternshipContext::class)->selected(auth()->user())->type === AffiliationType::InternshipOffice ? 'Buscar por discente, matrícula, protocolo do documento ou número' : 'Buscar por discente, matrícula ou número'" icon="magnifying-glass" maxlength="255" type="search" />
        <div class="min-w-0"><x-select wire:model.live="courseId" label="Curso" :value="$courseId" :options="$this->courseOptions" searchable /></div>
        <div class="min-w-0"><x-select wire:model.live="status" label="Situação" :value="$status" :options="$this->statusOptions" /></div>
        <div class="min-w-0"><x-select wire:model.live="advisorId" label="Orientador" :value="$advisorId" :options="$this->advisorOptions" searchable /></div>
        <div>
            @if ($search !== '' || $status !== 'all' || $courseId !== '' || $advisorId !== '' || $pendingCancellations)
                <flux:button class="w-full" variant="outline" wire:click="clearFilters">Limpar filtros</flux:button>
            @else
                <div aria-hidden="true" class="h-10"></div>
            @endif
        </div>
    </div>
    @if (app(StudentInternshipContext::class)->selected(auth()->user())->type === AffiliationType::InternshipOffice)
        <flux:checkbox wire:model.live="pendingCancellations" label="Somente com pedido de cancelamento pendente" />
    @endif
    @php($internships = $this->internships)
    <x-loading-overlay target="search,courseId,advisorId,status,pendingCancellations,clearFilters,gotoPage,nextPage,previousPage,resetPage" label="Atualizando lista…" :dim-while-loading="true">
        @if ($internships->isNotEmpty())
            <x-academic-internships-table :internships="$internships" :paginated="true" :is-office="app(StudentInternshipContext::class)->selected(auth()->user())->type === AffiliationType::InternshipOffice" />
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
                <flux:icon.briefcase class="mx-auto mb-4 size-8 text-zinc-400" />
                @if ($internships->total() > 0)
                    <flux:heading size="lg">Nenhum estágio nesta página</flux:heading>
                    <flux:button class="mt-5" variant="ghost" wire:click="resetPage">Ir para a primeira página</flux:button>
                @elseif ($search !== '' || $status !== 'all' || $courseId !== '' || $advisorId !== '' || $pendingCancellations)
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
