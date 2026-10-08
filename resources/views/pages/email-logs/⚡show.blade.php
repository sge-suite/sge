<?php

use App\Models\EmailDeliveryAttempt;
use App\Support\EmailLogAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Detalhes do envio')] class extends Component
{
    #[Locked]
    public int $attemptId;

    public function boot(): void
    {
        Gate::authorize('viewAny', EmailDeliveryAttempt::class);
    }

    public function mount(EmailDeliveryAttempt $attempt): void
    {
        $this->attemptId = $attempt->id;
    }

    #[Computed]
    public function delivery(): EmailDeliveryAttempt
    {
        $attempt = EmailDeliveryAttempt::findOrFail($this->attemptId);
        Gate::authorize('view', $attempt);

        return $attempt->load('emailMessage', 'requestedByAffiliation.user');
    }

    /** @return Collection<int, EmailDeliveryAttempt> */
    #[Computed]
    public function attempts(): Collection
    {
        $query = app(EmailLogAccess::class)->forCurrentContext(auth()->user(), app('session.store'));
        abort_if($query === null, 403);

        return $query->where('delivery_key', $this->delivery->delivery_key)->orderBy('attempt_number')->get();
    }
}; ?>

<div class="w-full space-y-8">
    @php($delivery = $this->delivery)
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
            <section class="space-y-4" aria-labelledby="email-attempts-heading">
                <flux:heading id="email-attempts-heading" size="lg" level="2">Tentativas de envio</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Tentativa</flux:table.column><flux:table.column>Situação</flux:table.column><flux:table.column>Na fila</flux:table.column><flux:table.column>Enviada</flux:table.column><flux:table.column>Falhou</flux:table.column><flux:table.column>Motivo</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->attempts as $attempt)
                            <flux:table.row :key="$attempt->id">
                                <flux:table.cell>{{ $attempt->attempt_number }}</flux:table.cell>
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
</div>
