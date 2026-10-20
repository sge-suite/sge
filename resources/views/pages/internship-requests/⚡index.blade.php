<?php

use App\Actions\ManageStudentInternshipRequest;
use App\Models\InternshipRequest;
use App\Support\PageSize;
use App\Support\StudentInternshipContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Minhas solicitações de estágio')] class extends Component
{
    use WithPagination;

    #[\Livewire\Attributes\Locked] public int $affiliationId;

    public function mount(StudentInternshipContext $context): void
    {
        $this->affiliationId = $context->student(Auth::user())->id;
    }

    public function hydrate(StudentInternshipContext $context): void
    {
        abort_unless($context->student(Auth::user())?->id === $this->affiliationId, 403);
    }

    public function boot(): void
    {
        Gate::authorize('viewAny', InternshipRequest::class);
    }

    public function start(ManageStudentInternshipRequest $action, StudentInternshipContext $context): void
    {
        $owner = $context->student(Auth::user(), writable: true);
        abort_if($owner === null, 403);
        $request = $action->handle(Auth::user(), $this->affiliationId, null, 'create');
        $this->redirectRoute('internship-requests.show', $request, navigate: true);
    }

    #[Computed]
    public function requests(): LengthAwarePaginator
    {
        $owner = app(StudentInternshipContext::class)->student(Auth::user());
        return $owner->internshipRequests()->with(['internshipType', 'grantingParty'])->latest('updated_at')->latest('id')->paginate(PageSize::PER_PAGE);
    }
}; ?>

<div class="w-full space-y-8">
    @if (session('status'))<div hidden x-data x-init="$nextTick(() => $flux.toast(@js(session('status')), { variant: 'success' }))"></div>@endif
    @php($requests = $this->requests)
    @php($canEditRequests = auth()->user()->can('create', InternshipRequest::class))
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>Painel</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Minhas solicitações</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading level="1" size="xl">Minhas solicitações de estágio</flux:heading>
            <flux:text class="mt-2">Comece seu estágio, continue um rascunho ou acompanhe a análise pelo Setor.</flux:text>
        </div>
        @if ($canEditRequests)
            <flux:button variant="primary" icon="plus" wire:click="start" wire:loading.attr="disabled" wire:target="start">Iniciar ou continuar solicitação</flux:button>
        @endif
    </div>
    <x-loading-overlay target="start,gotoPage,nextPage,previousPage" label="Atualizando solicitações…" :dim-while-loading="true">
        @if ($requests->isNotEmpty())
            <div class="mb-4 flex items-center gap-2 text-zinc-500 dark:text-zinc-400">
                <flux:icon.clipboard-document-list class="size-5" />
                <flux:text size="sm">{{ $requests->total() }} {{ $requests->total() === 1 ? 'solicitação registrada' : 'solicitações registradas' }}</flux:text>
            </div>
            <x-student-requests-table :$requests :can-edit="$canEditRequests" :paginated="true" />
        @else
            <flux:card class="flex flex-col items-center gap-3 border-dashed px-6 py-12 text-center">
                <flux:avatar icon="clipboard-document-list" icon-variant="outline" size="lg" class="bg-brand/10 text-brand [&>svg]:opacity-100" />
                <flux:heading level="2" size="lg">Seu próximo estágio começa aqui</flux:heading>
                <flux:text class="max-w-lg">Preencha sua solicitação por etapas, salve o rascunho e volte quando precisar. O envio para análise só acontece após sua confirmação.</flux:text>
            </flux:card>
        @endif
        @if ($requests->isEmpty())<div class="mt-6">{{ $requests->links() }}</div>@endif
    </x-loading-overlay>
</div>
