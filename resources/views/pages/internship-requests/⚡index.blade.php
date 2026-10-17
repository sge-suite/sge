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
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading level="1" size="xl">Minhas solicitações de estágio</flux:heading>
            <flux:text class="mt-2">Comece seu estágio, continue um rascunho ou acompanhe a análise pelo Setor.</flux:text>
        </div>
        @can('create', InternshipRequest::class)
            <flux:button variant="primary" wire:click="start" wire:loading.attr="disabled">Iniciar ou continuar solicitação</flux:button>
        @endcan
    </div>
    <div class="space-y-4">
        @forelse ($this->requests as $request)
            <article wire:key="request-{{ $request->id }}" class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <flux:heading level="2" size="lg">Solicitação nº {{ $request->id }}</flux:heading>
                    <flux:badge>{{ $request->status->label() }}</flux:badge>
                </div>
                <flux:text>{{ $request->internshipType?->name ?? 'Tipo de estágio ainda não escolhido' }} · {{ $request->grantingParty?->name ?? 'Concedente em preenchimento ou cadastro pendente' }}</flux:text>
                <flux:text size="sm">Atualizada em {{ formatDateTime($request->updated_at) }}</flux:text>
                <flux:text>{{ match ($request->status->value) {
                    'draft' => 'Continue o preenchimento e envie quando estiver tudo pronto.',
                    'pending_correction' => 'Há uma correção solicitada. Confira o motivo e as seções liberadas.',
                    'submitted', 'under_review' => 'Aguarde a análise do Setor de Estágio.',
                    'accepted' => 'Solicitação aceita. Aguarde as orientações sobre a documentação e a liberação do início.',
                    'rejected' => 'Consulte a orientação do Setor para entender a recusa.',
                    default => 'Esta solicitação foi encerrada por desistência.',
                } }}</flux:text>
                <flux:button :href="route('internship-requests.show', $request)" wire:navigate>{{ in_array($request->status->value, ['draft', 'pending_correction'], true) ? 'Continuar preenchimento' : 'Ver solicitação' }}</flux:button>
            </article>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-600">
                <flux:heading level="2" size="lg">Seu próximo estágio começa aqui</flux:heading>
                <flux:text class="mt-2">Você poderá preencher por etapas, salvar e voltar depois. O envio só acontece após sua confirmação.</flux:text>
            </div>
        @endforelse
    </div>
    {{ $this->requests->links() }}
</div>
