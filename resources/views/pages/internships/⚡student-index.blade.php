<?php

use App\Models\Internship;
use App\Support\PageSize;
use App\Support\StudentInternshipContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Meus estágios')] class extends Component
{
    use WithPagination;

    #[Locked]
    public int $affiliationId;

    public function boot(): void
    {
        Gate::authorize('viewOwnAny', Internship::class);
    }

    public function mount(StudentInternshipContext $context): void
    {
        $this->affiliationId = $context->student(auth()->user())->id;
    }

    public function hydrate(StudentInternshipContext $context): void
    {
        abort_unless($context->student(auth()->user())?->id === $this->affiliationId, 403);
    }

    /** @return LengthAwarePaginator<int, Internship> */
    #[Computed]
    public function internships(): LengthAwarePaginator
    {
        $owner = app(StudentInternshipContext::class)->student(auth()->user());

        return Internship::query()->where('student_affiliation_id', $owner->id)
            ->whereHas('course', fn ($query) => $query->where('campus_id', $owner->campus_id))
            ->with('advisorAffiliation.user')->latest('updated_at')->latest('id')->paginate(PageSize::PER_PAGE);
    }
};
?>

<div class="w-full space-y-8">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>Painel</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Meus estágios</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading level="1" size="xl">Meus estágios</flux:heading>
            <flux:text class="mt-2">Acompanhe a situação, a orientação e o período previsto dos seus estágios.</flux:text>
        </div>
        <flux:button variant="outline" icon="clipboard-document-list" :href="route('internship-requests.index')" wire:navigate>Minhas solicitações</flux:button>
    </div>
    @php($internships = $this->internships)
    <x-loading-overlay target="gotoPage,nextPage,previousPage" label="Atualizando estágios…" :dim-while-loading="true">
        @if ($internships->isNotEmpty())
            <x-student-internships-table :$internships :paginated="true" />
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-12 text-center dark:border-zinc-700">
                <flux:icon.briefcase class="mx-auto mb-4 size-8 text-zinc-400" />
                <flux:heading size="lg" level="2">Nenhum estágio registrado</flux:heading>
                <flux:text class="mt-2">Seu estágio aparecerá aqui após o aceite da solicitação pelo Setor de Estágio.</flux:text>
            </div>
            {{ $internships->links() }}
        @endif
    </x-loading-overlay>
</div>
