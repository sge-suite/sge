<?php

use App\Enums\InternshipRequestStatus;
use App\Models\Course;
use App\Models\InternshipRequest;
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

new #[Title('Analisar solicitações de estágio')] class extends Component
{
    use WithPagination;

    #[Locked]
    public int $affiliationId;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: '', as: 'course')]
    public string $courseId = '';

    public function boot(): void
    {
        Gate::authorize('viewReviewList', InternshipRequest::class);
    }

    public function mount(StudentInternshipContext $context): void
    {
        $this->affiliationId = $context->selected(Auth::user())->id;

        if (! array_key_exists($this->status, $this->statusOptions)) {
            $this->status = 'all';
        }

        if (! array_key_exists($this->courseId, $this->courseOptions)) {
            $this->courseId = '';
        }
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
        if (! array_key_exists($this->status, $this->statusOptions)) {
            $this->status = 'all';
        }

        $this->resetPage();
    }

    public function updatedCourseId(): void
    {
        if (! array_key_exists($this->courseId, $this->courseOptions)) {
            $this->courseId = '';
        }

        $this->resetPage();
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
        return ['all' => 'Todas'] + array_diff_key(InternshipRequestStatus::options(), array_flip(['draft', 'withdrawn']));
    }

    /** @return array<int|string, string> */
    #[Computed]
    public function courseOptions(): array
    {
        $owner = app(StudentInternshipContext::class)->selected(Auth::user());

        return ['' => 'Todos os cursos'] + Course::query()->where('campus_id', $owner->campus_id)
            ->orderBy('name')->orderBy('id')->pluck('name', 'id')->all();
    }

    #[Computed]
    public function requests(): LengthAwarePaginator
    {
        $owner = app(StudentInternshipContext::class)->selected(Auth::user());
        $search = mb_substr(trim($this->search), 0, 255);

        return InternshipRequest::query()->whereNotIn('status', ['draft', 'withdrawn'])
            ->whereHas('affiliation', fn (Builder $query) => $query->where('campus_id', $owner->campus_id))
            ->when($this->status !== 'all', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->courseId !== '', fn (Builder $query) => $query->where('course_id', $this->courseId))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_').'%';
                $query->where(function (Builder $query) use ($term, $search): void {
                    $query->whereLike('student_snapshot->name', $term, caseSensitive: false)
                        ->orWhereLike('student_snapshot->registration_number', $term, caseSensitive: false);

                    if (ctype_digit($search) && strlen($search) <= 18) {
                        $query->orWhere('id', (int) $search);
                    }
                });
            })
            ->with(['course', 'internshipType'])->latest('updated_at')->latest('id')->paginate(PageSize::PER_PAGE);
    }
}; ?>

<div class="w-full space-y-8">
    <div>
        <flux:heading level="1" size="xl">Analisar solicitações de estágio</flux:heading>
        <flux:text class="mt-2">Consulte e analise as solicitações enviadas pelos discentes do seu campus.</flux:text>
    </div>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_16rem_12rem_9rem] xl:items-end">
        <flux:input wire:model.live.debounce.300ms="search" label="Buscar por discente, matrícula ou número" icon="magnifying-glass" maxlength="255" type="search" />
        <div class="min-w-0">
            <x-select wire:model.live="courseId" label="Curso" :value="$courseId" :options="$this->courseOptions" searchable />
        </div>
        <div class="min-w-0">
            <x-select wire:model.live="status" label="Situação" :value="$status" :options="$this->statusOptions" />
        </div>
        <div>
            @if ($search !== '' || $status !== 'all' || $courseId !== '')
                <flux:button class="w-full" variant="outline" wire:click="clearFilters">Limpar filtros</flux:button>
            @else
                <div aria-hidden="true" class="h-10"></div>
            @endif
        </div>
    </div>
    @php($requests = $this->requests)
    <x-loading-overlay target="search,courseId,status,clearFilters,gotoPage,nextPage,previousPage,resetPage" label="Atualizando lista…" :dim-while-loading="true">
        @if ($requests->isNotEmpty())
            <flux:table :paginate="$requests" container:class="[&>ui-table-scroll-area]:max-h-[70vh]">
                <flux:table.columns sticky class="bg-white dark:bg-zinc-800">
                    <flux:table.column>Solicitação / Discente</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">Curso / Tipo de estágio</flux:table.column>
                    <flux:table.column>Situação</flux:table.column>
                    <flux:table.column class="hidden lg:table-cell">Última atualização</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($requests as $request)
                        <flux:table.row :key="$request->id" class="group cursor-pointer transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-request-link]').click()">
                            <flux:table.cell class="whitespace-normal">
                                <a data-request-link href="{{ route('internship-review.show', $request) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">Nº {{ $request->id }} · {{ $request->student_snapshot['name'] ?? 'Discente' }}</a>
                                <flux:text size="sm">Matrícula: {{ $request->student_snapshot['registration_number'] ?? 'Não informada' }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell class="hidden whitespace-normal md:table-cell">
                                {{ $request->course?->name ?? 'Não informado' }}
                                <flux:text size="sm">{{ $request->internshipType?->name ?? 'Não informado' }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="match ($request->status) { InternshipRequestStatus::Submitted => 'blue', InternshipRequestStatus::UnderReview => 'amber', InternshipRequestStatus::PendingCorrection => 'orange', InternshipRequestStatus::Accepted => 'green', InternshipRequestStatus::Rejected => 'red', default => 'zinc' }">{{ $request->status->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="hidden lg:table-cell">{{ formatDateTime($request->updated_at) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
                <flux:icon.document-text class="mx-auto mb-4 size-8 text-zinc-400" />
                @if ($requests->total() > 0)
                    <flux:heading size="lg">Nenhuma solicitação nesta página</flux:heading>
                    <flux:button class="mt-5" variant="ghost" wire:click="resetPage">Ir para a primeira página</flux:button>
                @elseif ($search !== '' || $status !== 'all' || $courseId !== '')
                    <flux:heading size="lg">Nenhuma solicitação encontrada</flux:heading>
                    <flux:text class="mt-2">Ajuste a busca ou os filtros para encontrar uma solicitação.</flux:text>
                    <flux:button class="mt-5" variant="ghost" wire:click="clearFilters">Limpar filtros</flux:button>
                @else
                    <flux:heading size="lg">Nenhuma solicitação enviada</flux:heading>
                    <flux:text class="mt-2">As solicitações enviadas pelos discentes do seu campus aparecerão aqui.</flux:text>
                @endif
            </div>
        @endif
    </x-loading-overlay>
</div>
