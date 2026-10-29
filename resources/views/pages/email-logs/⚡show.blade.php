<?php

use App\Actions\RequestEmailDelivery;
use App\Enums\EmailDeliveryAttemptStatus;
use App\Models\EmailDeliveryAttempt;
use App\Support\ActiveAffiliationContext;
use App\Support\EmailLogAccess;
use App\Support\PageSize;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Detalhes do envio')] class extends Component
{
    use WithPagination;

    #[Locked]
    public int $attemptId;

    #[Locked]
    public ?int $openedByAffiliationId = null;

    #[Locked]
    public ?int $confirmedAttemptId = null;

    #[Locked]
    public ?int $requestedAttemptId = null;

    #[Locked]
    public string $retryFeedback = '';

    public bool $showRetryConfirmation = false;

    public function boot(): void
    {
        Gate::authorize('viewAny', EmailDeliveryAttempt::class);
    }

    public function mount(EmailDeliveryAttempt $attempt): void
    {
        $this->attemptId = $attempt->id;
        $this->openedByAffiliationId = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'))?->id;
    }

    #[Computed]
    public function delivery(): EmailDeliveryAttempt
    {
        $attempt = EmailDeliveryAttempt::findOrFail($this->attemptId);
        Gate::authorize('view', $attempt);

        return $attempt->load('emailMessage', 'requestedByAffiliation.user');
    }

    /** @return LengthAwarePaginator<int, EmailDeliveryAttempt> */
    #[Computed]
    public function attempts(): LengthAwarePaginator
    {
        $query = app(EmailLogAccess::class)->forCurrentContext(auth()->user(), app('session.store'));
        abort_if($query === null, 403);

        return $query->where('delivery_key', $this->delivery->delivery_key)
            ->orderBy('attempt_number')
            ->paginate(PageSize::PER_PAGE, pageName: 'attemptsPage');
    }

    #[Computed]
    public function latestAttempt(): EmailDeliveryAttempt
    {
        $latest = EmailDeliveryAttempt::query()->with('emailMessage')
            ->where('delivery_key', $this->delivery->delivery_key)->orderByDesc('attempt_number')->firstOrFail();
        Gate::authorize('view', $latest);

        return $latest;
    }

    #[Computed]
    public function confirmedAttempt(): ?EmailDeliveryAttempt
    {
        if ($this->confirmedAttemptId === null) {
            return null;
        }

        $attempt = EmailDeliveryAttempt::query()->with('emailMessage')
            ->where('delivery_key', $this->delivery->delivery_key)->findOrFail($this->confirmedAttemptId);
        Gate::authorize('view', $attempt);

        return $attempt;
    }

    public function openRetryConfirmation(): void
    {
        $this->resetErrorBag();
        $this->retryFeedback = '';
        $this->showRetryConfirmation = false;
        $this->confirmedAttemptId = null;
        unset($this->latestAttempt, $this->confirmedAttempt, $this->attempts);

        try {
            $this->assertOriginalAffiliation();
            Gate::authorize('retry', $this->latestAttempt);

            if (($reason = $this->latestAttempt->retryUnavailableReason()) !== null) {
                throw ValidationException::withMessages(['retry' => $reason]);
            }

            $this->confirmedAttemptId = $this->latestAttempt->id;
            $this->showRetryConfirmation = true;
        } catch (AuthorizationException) {
            $this->addError('retry', 'Seu vínculo atual não pode solicitar outra tentativa para este envio.');
        } catch (ValidationException $exception) {
            $this->addError('retry', $exception->validator->errors()->first());
        }
    }

    public function retryDelivery(RequestEmailDelivery $deliveries): void
    {
        $this->resetErrorBag();

        try {
            $this->assertOriginalAffiliation();

            if (! $this->showRetryConfirmation || $this->confirmedAttemptId === null) {
                throw ValidationException::withMessages(['retry' => 'Abra a confirmação antes de solicitar outra tentativa.']);
            }

            $attempt = $deliveries->retry($this->delivery, auth()->user(), $this->confirmedAttemptId, $this->openedByAffiliationId);
            $this->requestedAttemptId = $attempt->wasRecentlyCreated ? $attempt->id : null;
            $this->retryFeedback = $attempt->wasRecentlyCreated
                ? "Tentativa solicitada: #{$attempt->id}, {$attempt->attempt_number} de 3. Acompanhe a situação abaixo."
                : 'O histórico mudou desde a confirmação. Nenhuma nova tentativa foi criada por esta confirmação. Confira a situação mais recente.';
            $this->showRetryConfirmation = false;
        } catch (AuthorizationException) {
            $this->addError('retry', 'Seu vínculo atual não pode solicitar outra tentativa para este envio.');
        } catch (ValidationException $exception) {
            $this->addError('retry', $exception->validator->errors()->first());
        } catch (\Throwable) {
            $this->addError('retry', 'Não foi possível confirmar o enfileiramento. Confira a situação e o histórico antes de tentar novamente.');
        } finally {
            unset($this->delivery, $this->latestAttempt, $this->attempts, $this->confirmedAttempt);
            $this->resetPage(pageName: 'attemptsPage');
        }
    }

    private function assertOriginalAffiliation(): void
    {
        $current = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'));

        if ($this->openedByAffiliationId === null || $current?->id !== $this->openedByAffiliationId) {
            throw ValidationException::withMessages(['retry' => 'O vínculo selecionado mudou. Reabra o detalhe do envio antes de solicitar outra tentativa.']);
        }
    }
}; ?>

<div class="w-full space-y-8">
    @php($delivery = $this->delivery)
    @php($latest = $this->latestAttempt)
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('email-logs.index')" wire:navigate>E-mails</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Envio #{{ $attemptId }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ $delivery->emailMessage?->subject ?? $delivery->purpose->label() }}</flux:heading>
        <flux:button variant="outline" icon="arrow-left" :href="route('email-logs.index')" wire:navigate>Voltar</flux:button>
    </div>
    <div class="grid items-start gap-8 xl:grid-cols-2" data-email-preview-layout>
        <div class="min-w-0 space-y-8">
            <section class="min-w-0 space-y-4" aria-labelledby="email-details-heading">
                <flux:heading id="email-details-heading" size="lg" level="2">Detalhes do envio</flux:heading>
                <div class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700" data-email-details-card>
                    <dl class="grid gap-5 sm:grid-cols-2 xl:grid-cols-1">
                        <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Destinatário</dt><dd class="mt-1 break-all">{{ $delivery->recipient_email }}</dd></div>
                        <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Finalidade</dt><dd class="mt-1">{{ $delivery->purpose->label() }}</dd></div>
                        <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Solicitado por</dt><dd class="mt-1">
                            @if ($delivery->requestedByAffiliation)
                                {{ $delivery->requestedByAffiliation->user?->name }} · {{ $delivery->requestedByAffiliation->type->label() }} · Vínculo #{{ $delivery->requested_by_affiliation_id }} (identificação atual)
                            @else
                                Solicitante não registrado
                            @endif
                        </dd></div>
                        <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Registrado em</dt><dd class="mt-1">{{ formatDateTime($delivery->created_at) }}</dd></div>
                    </dl>
                    <flux:text class="mt-6">“Enviada” indica que o transporte aceitou a mensagem; não confirma recebimento ou leitura.</flux:text>
                </div>
            </section>
            <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700" aria-labelledby="email-latest-heading" @if ($latest->status === EmailDeliveryAttemptStatus::Queued) wire:poll.10s @endif>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <flux:heading id="email-latest-heading" size="lg" level="2">Situação mais recente</flux:heading>
                    <flux:badge :color="match ($latest->status) { EmailDeliveryAttemptStatus::Sent => 'green', EmailDeliveryAttemptStatus::Failed => 'red', default => 'zinc' }">{{ $latest->status->label() }}</flux:badge>
                </div>
                <flux:text>Tentativa #{{ $latest->id }} · {{ $latest->attempt_number }} de 3 · solicitada em {{ formatDateTime($latest->queued_at) }}</flux:text>
                @if ($retryFeedback !== '')
                    <flux:text role="status">{{ $retryFeedback }}</flux:text>
                @endif
                @if (! $showRetryConfirmation)
                    <flux:error name="retry" />
                @endif
                @if (($retryReason = $latest->retryUnavailableReason()) !== null)
                    <flux:text>{{ $retryReason }}</flux:text>
                @else
                    @can('retry', $latest)
                        <div><flux:button variant="primary" wire:click="openRetryConfirmation" wire:loading.attr="disabled" wire:target="openRetryConfirmation,retryDelivery">Tentar enviar novamente</flux:button></div>
                    @else
                        <flux:text>Seu vínculo atual permite somente consultar este envio. O Setor precisa de um campus ativo para solicitar outra tentativa.</flux:text>
                    @endcan
                @endif
            </section>
            <section class="space-y-4" aria-labelledby="email-attempts-heading">
                <flux:heading id="email-attempts-heading" size="lg" level="2">Tentativas de envio</flux:heading>
                <flux:table :paginate="$this->attempts">
                    <flux:table.columns>
                        <flux:table.column>Tentativa</flux:table.column><flux:table.column>Situação</flux:table.column><flux:table.column>Na fila</flux:table.column><flux:table.column>Enviada</flux:table.column><flux:table.column>Falhou</flux:table.column><flux:table.column>Motivo</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->attempts as $attempt)
                            <flux:table.row :key="$attempt->id">
                                <flux:table.cell>{{ $attempt->attempt_number }}@if ($requestedAttemptId === $attempt->id) <flux:badge size="sm">Tentativa solicitada</flux:badge> @endif</flux:table.cell>
                                <flux:table.cell><flux:badge size="sm" :color="match ($attempt->status->value) { 'sent' => 'green', 'failed' => 'red', default => 'zinc' }">{{ $attempt->status->label() }}</flux:badge></flux:table.cell>
                                <flux:table.cell>{{ formatDateTime($attempt->queued_at) }}</flux:table.cell>
                                <flux:table.cell>{{ formatDateTime($attempt->sent_at) }}</flux:table.cell>
                                <flux:table.cell>{{ formatDateTime($attempt->failed_at) }}</flux:table.cell>
                                <flux:table.cell class="whitespace-normal">{{ match ($attempt->failure_reason) { 'transport_failed' => 'Falha no transporte', 'worker_failed' => 'Falha no processamento', null => '—', default => $attempt->failure_reason } }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </section>
        </div>
        <section class="w-full min-w-0 space-y-4" aria-labelledby="email-content-heading">
            <flux:heading id="email-content-heading" size="lg" level="2">Conteúdo registrado</flux:heading>
            <x-email-logs.content-preview :message="$delivery->emailMessage" />
        </section>
    </div>
    <flux:modal wire:model.self="showRetryConfirmation" class="max-w-lg">
        @if ($showRetryConfirmation && ($confirmed = $this->confirmedAttempt))
            <form wire:submit="retryDelivery" class="flex flex-col gap-5">
                <flux:heading size="lg">Solicitar nova tentativa</flux:heading>
                <dl class="grid gap-4">
                    <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Destinatário histórico</dt><dd class="mt-1 break-all">{{ $confirmed->recipient_email }}</dd></div>
                    <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Assunto</dt><dd class="mt-1 break-words">{{ $confirmed->emailMessage?->subject }}</dd></div>
                    <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Finalidade</dt><dd class="mt-1">{{ $confirmed->purpose->label() }}</dd></div>
                    <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Próxima tentativa</dt><dd class="mt-1">{{ $confirmed->attempt_number + 1 }} de 3</dd></div>
                </dl>
                <flux:text>O conteúdo registrado e o destinatário serão mantidos, mesmo que o cadastro tenha mudado. A solicitação coloca uma tentativa na fila.</flux:text>
                <flux:text>Uma falha de processamento pode ocorrer após o servidor de e-mail aceitar a mensagem. Uma nova tentativa pode causar entrega duplicada.</flux:text>
                <flux:error name="retry" />
                <div class="flex flex-wrap justify-end gap-3">
                    <flux:button type="button" variant="outline" wire:click="$set('showRetryConfirmation', false)" wire:loading.attr="disabled" wire:target="retryDelivery">Cancelar</flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="retryDelivery">
                        <span wire:loading.remove wire:target="retryDelivery">Solicitar nova tentativa</span>
                        <span wire:loading wire:target="retryDelivery">Solicitando…</span>
                    </flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
