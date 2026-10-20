<?php

use App\Enums\InternshipStatus;
use App\Enums\AffiliationType;
use App\Models\Course;
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

    private function normalizeFilters(): void
    {
        if (! array_key_exists($this->status, $this->statusOptions)) {
            $this->status = 'all';
        }
        if (! array_key_exists($this->courseId, $this->courseOptions)) {
            $this->courseId = '';
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'courseId');
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
            ->when($owner->type === AffiliationType::Coordinator, fn ($query) => $query->where(fn ($query) => $query
                ->where('primary_coordinator_affiliation_id', $owner->id)->orWhere('secondary_coordinator_affiliation_id', $owner->id)))
            ->orderBy('name')->orderBy('id')->pluck('name', 'id')->all();
    }

    /** @return LengthAwarePaginator<int, Internship> */
    #[Computed]
    public function internships(): LengthAwarePaginator
    {
        Gate::authorize('viewAny', Internship::class);
        $owner = app(StudentInternshipContext::class)->selected(Auth::user());
        $search = mb_substr(trim($this->search), 0, 255);

        return Internship::query()->whereHas('course', fn (Builder $query) => $query->where('campus_id', $owner->campus_id))
            ->when($owner->type === AffiliationType::Coordinator, fn ($query) => $query->whereHas('course', fn ($query) => $query
                ->where(fn ($query) => $query->where('primary_coordinator_affiliation_id', $owner->id)->orWhere('secondary_coordinator_affiliation_id', $owner->id))))
            ->when($this->status !== 'all', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->courseId !== '', fn (Builder $query) => $query->where('course_id', $this->courseId))
            ->when($search !== '', function (Builder $query) use ($search, $owner): void {
                $term = '%'.addcslashes($search, '%_').'%';
                $query->where(function (Builder $query) use ($term, $search, $owner): void {
                    $query->whereLike('student_snapshot->name', $term, caseSensitive: false)
                        ->orWhereLike('student_snapshot->registration_number', $term, caseSensitive: false);
                    if ($owner->type === AffiliationType::InternshipOffice) {
                        $query->orWhereLike('protocol_number', $term, caseSensitive: false);
                    }
                    if (ctype_digit($search) && strlen($search) <= 18) {
                        $query->orWhere('id', (int) $search);
                    }
                });
            })->with('course')->latest('updated_at')->latest('id')->paginate(PageSize::PER_PAGE);
    }
}; ?>

<div class="w-full space-y-8">
    <div>
        <flux:heading level="1" size="xl">Estágios</flux:heading>
        <flux:text class="mt-2">Acompanhe os estágios do campus, suas pausas e a previsão de término.</flux:text>
    </div>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_16rem_14rem_9rem] xl:items-end">
        <flux:input wire:model.live.debounce.300ms="search" label="Buscar por discente, matrícula, protocolo ou número" icon="magnifying-glass" maxlength="255" type="search" />
        <div class="min-w-0"><x-select wire:model.live="courseId" label="Curso" :value="$courseId" :options="$this->courseOptions" searchable /></div>
        <div class="min-w-0"><x-select wire:model.live="status" label="Situação" :value="$status" :options="$this->statusOptions" /></div>
        <div>
            @if ($search !== '' || $status !== 'all' || $courseId !== '')
                <flux:button class="w-full" variant="outline" wire:click="clearFilters">Limpar filtros</flux:button>
            @else
                <div aria-hidden="true" class="h-10"></div>
            @endif
        </div>
    </div>
    @php($internships = $this->internships)
    <x-loading-overlay target="search,courseId,status,clearFilters,gotoPage,nextPage,previousPage,resetPage" label="Atualizando lista…" :dim-while-loading="true">
        @if ($internships->isNotEmpty())
            <flux:table :paginate="$internships" container:class="[&>ui-table-scroll-area]:max-h-[70vh]">
                <flux:table.columns sticky class="bg-white dark:bg-zinc-800">
                    <flux:table.column>Estágio / Discente</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">Curso / Tipo</flux:table.column>
                    <flux:table.column>Situação</flux:table.column>
                    <flux:table.column class="hidden lg:table-cell">Início / Término previsto</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($internships as $internship)
                        <flux:table.row :key="'internship-'.$internship->id" class="group cursor-pointer transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-internship-link]').click()">
                            <flux:table.cell class="whitespace-normal">
                                <a data-internship-link href="{{ route('internships.show', $internship) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">Nº {{ $internship->id }} · {{ $internship->student_snapshot['name'] ?? 'Discente' }}</a>
                                <flux:text size="sm">Matrícula: {{ $internship->student_snapshot['registration_number'] ?? 'Não informada' }}</flux:text>
                                @if ($internship->protocol_number && app(StudentInternshipContext::class)->selected(auth()->user())->type === AffiliationType::InternshipOffice)<flux:text size="sm">Protocolo: {{ $internship->protocol_number }}</flux:text>@endif
                            </flux:table.cell>
                            <flux:table.cell class="hidden whitespace-normal md:table-cell">{{ $internship->course?->name }}<flux:text size="sm">{{ $internship->internship_type_snapshot['name'] ?? 'Não informado' }}</flux:text></flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="match ($internship->status) { InternshipStatus::Released, InternshipStatus::InProgress => 'green', InternshipStatus::AwaitingSignatures, InternshipStatus::Paused => 'amber', InternshipStatus::PendingCorrection => 'orange', InternshipStatus::Completed => 'blue', InternshipStatus::Cancelled => 'red', default => 'zinc' }">{{ $internship->status->label() }}</flux:badge></flux:table.cell>
                            <flux:table.cell class="hidden lg:table-cell">{{ formatShort($internship->planned_start_date) }}<flux:text size="sm">{{ formatShort($internship->projected_end_date) }}</flux:text></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
                <flux:icon.briefcase class="mx-auto mb-4 size-8 text-zinc-400" />
                @if ($internships->total() > 0)
                    <flux:heading size="lg">Nenhum estágio nesta página</flux:heading>
                    <flux:button class="mt-5" variant="ghost" wire:click="resetPage">Ir para a primeira página</flux:button>
                @elseif ($search !== '' || $status !== 'all' || $courseId !== '')
                    <flux:heading size="lg">Nenhum estágio encontrado</flux:heading>
                    <flux:text class="mt-2">Ajuste a busca ou os filtros para encontrar um estágio.</flux:text>
                    <flux:button class="mt-5" variant="ghost" wire:click="clearFilters">Limpar filtros</flux:button>
                @else
                    <flux:heading size="lg">Nenhum estágio cadastrado</flux:heading>
                    <flux:text class="mt-2">Os estágios criados no aceite das solicitações aparecerão aqui.</flux:text>
                @endif
            </div>
        @endif
    </x-loading-overlay>
</div>
