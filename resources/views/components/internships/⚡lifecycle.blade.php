<?php

use App\Actions\ManageInternshipLifecycle;
use App\Enums\InternshipCancellationRequestStatus;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Models\Internship;
use App\Models\InternshipCancellationRequest;
use App\Support\CancellationReasonOptions;
use App\Support\StudentInternshipContext;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Locked] public int $internshipId;
    #[Locked] public int $affiliationId;
    #[Locked] public string $operation = '';
    #[Locked] public ?int $requestId = null;
    public bool $showOperation = false;
    public bool $confirmHours = false;
    public string $reason = '';
    public string $effectiveDate = '';

    public function mount(int $internshipId, StudentInternshipContext $context): void
    {
        $this->internshipId = $internshipId;
        Gate::authorize('viewLifecycle', $this->internship);
        $this->affiliationId = $context->selected(auth()->user())->id;
    }

    public function hydrate(StudentInternshipContext $context): void
    {
        abort_unless($context->selected(auth()->user())?->id === $this->affiliationId, 403, 'O vínculo selecionado mudou. Reabra o estágio.');
        Gate::authorize('viewLifecycle', $this->internship);
    }

    #[Computed]
    public function internship(): Internship
    {
        $internship = Internship::query()->with('course.campus')->findOrFail($this->internshipId);
        Gate::authorize('viewLifecycle', $internship);

        return $internship;
    }

    #[Computed]
    public function pendingCancellation(): ?InternshipCancellationRequest
    {
        return $this->internship->cancellationRequests()->whereIn('status', [InternshipCancellationRequestStatus::Submitted, InternshipCancellationRequestStatus::UnderReview])->first();
    }

    /** @return Collection<int, InternshipCancellationRequest> */
    #[Computed]
    public function cancellations(): Collection
    {
        return $this->internship->cancellationRequests()->latest('id')->limit(10)->get();
    }

    /** @return array<string, string> */
    #[Computed]
    public function reasonOptions(): array
    {
        return app(CancellationReasonOptions::class)->forUser(auth()->user());
    }

    /** @return list<array{label: string, met: bool, description: string, href: string, action: string}> */
    #[Computed]
    public function releaseRequirements(): array
    {
        Gate::authorize('release', $this->internship);
        $documents = $this->internship->generatedDocuments()->where('type', GeneratedDocumentType::Main)
            ->where('status', '!=', GeneratedDocumentStatus::Cancelled)->with(['templateVersion.documentTemplate', 'externalRegistration'])->orderBy('id')->get();
        $requirements = [['label' => 'Pedido de cancelamento', 'met' => $this->pendingCancellation === null,
            'description' => $this->pendingCancellation === null ? 'Não há pedido de cancelamento em análise.' : 'Decida o pedido nº '.$this->pendingCancellation->id.' antes de liberar o estágio.',
            'href' => '#lifecycle-heading', 'action' => 'Conferir pedido de cancelamento']];
        if ($documents->isEmpty()) {
            $requirements[] = ['label' => 'Documentos principais', 'met' => false, 'description' => 'Não há documento principal vigente. Gere ou registre o documento e confira as assinaturas.',
                'href' => '#internship-documents', 'action' => 'Ir aos documentos'];
        }
        foreach ($documents as $document) {
            $name = $document->snapshot['template_name'] ?? $document->externalRegistration?->properties->get('document_name') ?? $document->templateVersion?->documentTemplate?->name ?? 'Documento externo';
            $requirements[] = ['label' => 'Documento nº '.$document->id.' · '.$name.($document->templateVersion === null ? '' : ' · v'.$document->templateVersion->version),
                'met' => $document->status === GeneratedDocumentStatus::Signed, 'description' => $document->status->label().'. Todos os documentos principais vigentes precisam estar assinados.',
                'href' => '#internship-documents', 'action' => 'Conferir este número na lista de documentos'];
        }

        return $requirements;
    }

    #[On('internship-updated')]
    public function refreshRequirements(): void
    {
        Gate::authorize('viewLifecycle', $this->internship);
        unset($this->internship, $this->pendingCancellation, $this->cancellations, $this->releaseRequirements);
    }

    public function openOperation(string $operation, ?int $requestId = null): void
    {
        $ability = match ($operation) {
            'release' => 'release', 'complete' => 'complete', 'cancel' => 'cancel',
            'request_cancellation' => 'requestCancellation', 'withdraw_cancellation' => 'withdrawCancellation',
            'begin_cancellation', 'approve_cancellation', 'reject_cancellation' => 'reviewCancellation',
            default => abort(404),
        };
        Gate::authorize($ability, $this->internship);
        $this->resetValidation();
        $this->operation = $operation;
        $this->requestId = $requestId;
        $this->reason = '';
        $this->confirmHours = false;
        $this->effectiveDate = now()->toDateString();
        if ($requestId !== null) {
            $request = $this->internship->cancellationRequests()->findOrFail($requestId);
            abort_unless(in_array($request->status, [InternshipCancellationRequestStatus::Submitted, InternshipCancellationRequestStatus::UnderReview], true), 409);
            if ($operation === 'approve_cancellation') {
                $this->reason = $request->reason;
            }
        }
        $this->showOperation = true;
    }

    public function confirm(ManageInternshipLifecycle $lifecycle): void
    {
        $lifecycle->handle(auth()->user(), $this->internship, $this->affiliationId, $this->operation,
            ['reason' => $this->reason, 'effective_date' => $this->effectiveDate, 'confirm_hours' => $this->confirmHours], $this->requestId);
        $this->reset('showOperation', 'operation', 'requestId', 'reason', 'confirmHours');
        unset($this->internship, $this->pendingCancellation, $this->cancellations, $this->reasonOptions);
        $this->dispatch('internship-updated');
        Flux::toast('Operação registrada.', variant: 'success');
    }
}; ?>

<section class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="lifecycle-heading">
    <flux:heading id="lifecycle-heading" size="lg" level="2">Situação e encerramento</flux:heading>
    <flux:text>As operações abaixo conferem os requisitos, registram a decisão no histórico e enviam os avisos correspondentes.</flux:text>
    @can('release', $this->internship)
        <x-operation-requirements :items="$this->releaseRequirements" title="Antes de liberar o estágio" id="release-requirements" />
        <flux:button size="sm" variant="ghost" wire:click="refreshRequirements" wire:loading.attr="disabled">Atualizar conferência</flux:button>
    @endcan
    @can('complete', $this->internship)
        @if ($this->pendingCancellation !== null)
            <flux:callout variant="warning" heading="Conclusão aguardando decisão do cancelamento">Decida o pedido de cancelamento nº {{ $this->pendingCancellation->id }} nas ações abaixo antes de concluir o estágio.</flux:callout>
        @endif
    @endcan
    <div class="flex flex-wrap gap-3">
        @can('release', $this->internship)<flux:button variant="primary" wire:click="openOperation('release')" :disabled="collect($this->releaseRequirements)->contains(fn ($item) => ! $item['met'])">Liberar estágio</flux:button>@endcan
        @can('complete', $this->internship)<flux:button variant="primary" wire:click="openOperation('complete')" :disabled="$this->pendingCancellation !== null">Concluir estágio</flux:button>@endcan
        @if ($this->pendingCancellation === null)
            @can('cancel', $this->internship)<flux:button variant="danger" wire:click="openOperation('cancel')">Cancelar estágio</flux:button>@endcan
            @can('requestCancellation', $this->internship)<flux:button wire:click="openOperation('request_cancellation')">Solicitar cancelamento</flux:button>@endcan
        @else
            @php($pending = $this->pendingCancellation)
            @can('withdrawCancellation', $this->internship)<flux:button wire:click="openOperation('withdraw_cancellation', {{ $pending->id }})">Retirar pedido de cancelamento</flux:button>@endcan
            @can('reviewCancellation', $this->internship)
                @if ($pending->status === InternshipCancellationRequestStatus::Submitted)<flux:button wire:click="openOperation('begin_cancellation', {{ $pending->id }})">Iniciar análise do cancelamento</flux:button>@endif
                <flux:button variant="danger" wire:click="openOperation('approve_cancellation', {{ $pending->id }})">Aprovar cancelamento</flux:button>
                <flux:button wire:click="openOperation('reject_cancellation', {{ $pending->id }})">Recusar cancelamento</flux:button>
            @endcan
        @endif
    </div>
    @foreach ($this->cancellations as $cancellation)
        <div wire:key="cancellation-{{ $cancellation->id }}" class="space-y-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex flex-wrap items-center gap-3"><flux:heading level="3">Cancelamento nº {{ $cancellation->id }}</flux:heading><flux:badge size="sm">{{ $cancellation->status->label() }}</flux:badge></div>
            <flux:text class="whitespace-pre-line">{{ $cancellation->reason }}</flux:text>
            @if ($cancellation->decision_reason)<flux:text class="whitespace-pre-line">Decisão do Setor: {{ $cancellation->decision_reason }}</flux:text>@endif
            @if ($cancellation->effective_date)<flux:text>Efeito em {{ formatShort($cancellation->effective_date) }}</flux:text>@endif
        </div>
    @endforeach
    <flux:modal wire:model.self="showOperation" class="md:w-xl">
        <form wire:submit="confirm" class="space-y-6" data-unsaved-changes="dialog-confirm" data-unsaved-models='["reason", "effectiveDate", "confirmHours"]' data-unsaved-actions='["confirm"]'>
            <x-form-save-status />
            <flux:heading size="lg" level="2">{{ match ($operation) { 'release' => 'Liberar estágio', 'complete' => 'Concluir estágio', 'cancel' => 'Cancelar estágio', 'request_cancellation' => 'Solicitar cancelamento', 'withdraw_cancellation' => 'Retirar pedido de cancelamento', 'begin_cancellation' => 'Iniciar análise do cancelamento', 'approve_cancellation' => 'Aprovar cancelamento', 'reject_cancellation' => 'Recusar cancelamento', default => 'Confirmar operação' } }}</flux:heading>
            @if ($operation === 'release')
                <flux:text>Todos os documentos principais vigentes precisam estar com as assinaturas conferidas. A liberação registra seu vínculo e avisa os envolvidos. Se a data de início já chegou, o estágio passa para execução conforme as pausas vigentes.</flux:text>
            @elseif ($operation === 'complete')
                <flux:text>A conclusão preserva documentos, notas e histórico. A previsão de término não substitui a conferência da carga horária.</flux:text>
                <flux:checkbox wire:model="confirmHours" label="Confirmo que a carga horária integral deste estágio foi cumprida." /><flux:error name="confirm_hours" />
            @elseif (in_array($operation, ['cancel', 'request_cancellation', 'approve_cancellation', 'reject_cancellation'], true))
                <x-select-create wire:model="reason" :value="$reason" :options="$this->reasonOptions" label="Motivo / justificativa" :error="$errors->first('reason')" required placeholder="Escolha um motivo anterior ou escreva um novo" />
                @if (in_array($operation, ['cancel', 'approve_cancellation'], true))
                    <flux:input type="date" wire:model="effectiveDate" label="Data efetiva do cancelamento" :max="now()->toDateString()" required /><flux:error name="effective_date" />
                    <flux:callout variant="warning" heading="Efeitos do cancelamento">Documentos ainda não assinados e avaliações pendentes serão cancelados. Documentos assinados, avaliações aprovadas, notas e histórico serão preservados. Discente, orientador e supervisor serão avisados.</flux:callout>
                @endif
            @elseif ($operation === 'withdraw_cancellation')
                <flux:text>O pedido será retirado da análise. A situação do estágio permanece a mesma.</flux:text>
            @else
                <flux:text>O pedido passará para análise do Setor de Estágio.</flux:text>
            @endif
            <flux:error name="lifecycle" /><flux:error name="status" /><flux:error name="internship_id" /><flux:error name="submitted_at" />
            <div class="flex flex-wrap justify-end gap-3"><flux:button type="button" wire:click="$set('showOperation', false)">Voltar</flux:button><flux:button type="submit" :variant="in_array($operation, ['cancel', 'approve_cancellation'], true) ? 'danger' : 'primary'" wire:loading.attr="disabled" wire:target="confirm">Confirmar operação</flux:button></div>
            <flux:text size="sm" wire:loading wire:target="confirm">Registrando operação…</flux:text>
        </form>
    </flux:modal>
</section>
