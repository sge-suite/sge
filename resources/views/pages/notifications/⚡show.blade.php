<?php

use App\Actions\UpdateNotificationReadState;
use App\Support\NotificationDestinations;
use App\Support\NotificationInbox;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Detalhes da notificação')] class extends Component
{
    #[Locked]
    public int $affiliationId;

    #[Locked]
    public string $notificationId;

    public function mount(string $notification, NotificationInbox $inbox): void
    {
        $this->affiliationId = $inbox->affiliation(auth()->user())->id;
        abort_unless(Str::isUuid($notification), 404);
        $this->notificationId = $notification;
    }

    public function hydrate(NotificationInbox $inbox): void
    {
        $inbox->affiliation(auth()->user(), $this->affiliationId);
    }

    /** @return array{notification: DatabaseNotification, destination: string|null} */
    #[Computed]
    public function details(): array
    {
        $notification = app(NotificationInbox::class)->query(auth()->user(), $this->affiliationId)
            ->whereKey($this->notificationId)->firstOrFail();
        $destinations = app(NotificationDestinations::class)->forPage(auth()->user(), $this->affiliationId, collect([$notification]));

        return ['notification' => $notification, 'destination' => $destinations[$notification->id] ?? null];
    }

    public function markAsRead(UpdateNotificationReadState $update): void
    {
        $update->handle(auth()->user(), $this->affiliationId, $this->notificationId, read: true);
        $this->refreshNotification();
    }

    public function markAsUnread(UpdateNotificationReadState $update): void
    {
        $update->handle(auth()->user(), $this->affiliationId, $this->notificationId, read: false);
        $this->refreshNotification();
    }

    private function refreshNotification(): void
    {
        app(NotificationInbox::class)->affiliation(auth()->user(), $this->affiliationId);
        unset($this->details);
        $this->dispatch('notifications-updated');
    }
}; ?>

<div class="w-full space-y-8">
    @php
        $details = $this->details;
        $notification = $details['notification'];
        $destination = $details['destination'];
        $data = Arr::wrap($notification->data);
        $title = is_string($data['title'] ?? null) && trim($data['title']) !== '' ? $data['title'] : 'Notificação';
        $message = is_string($data['message'] ?? null) && trim($data['message']) !== '' ? $data['message'] : 'Este aviso não possui mensagem disponível.';
    @endphp

    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('notifications.index')" wire:navigate>Notificações</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Detalhes</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
            <flux:heading size="xl" level="1" class="wrap-anywhere">{{ $title }}</flux:heading>
            <flux:text class="mt-2">Aviso destinado ao vínculo selecionado.</flux:text>
        </div>
        <flux:button variant="outline" icon="arrow-left" :href="route('notifications.index')" wire:navigate>Voltar</flux:button>
    </div>

    <x-loading-overlay target="markAsRead,markAsUnread" label="Atualizando notificação…" :dim-while-loading="true">
        <div class="space-y-6 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
            <dl class="grid gap-5 sm:grid-cols-3">
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">Recebida em</dt>
                    <dd class="mt-1">{{ formatDateTime($notification->created_at) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">Leitura no SGE</dt>
                    <dd class="mt-1" role="status" aria-live="polite"><flux:badge size="sm" :color="$notification->unread() ? 'blue' : 'zinc'" :icon="$notification->unread() ? 'envelope' : 'check'">{{ $notification->unread() ? 'Não lida' : 'Lida' }}</flux:badge></dd>
                </div>
                @if ($notification->read())
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Marcada como lida em</dt>
                        <dd class="mt-1">{{ formatDateTime($notification->read_at) }}</dd>
                    </div>
                @endif
            </dl>

            <flux:separator variant="subtle" />

            <section class="space-y-3" aria-labelledby="notification-message-heading">
                <flux:heading id="notification-message-heading" level="2">Mensagem</flux:heading>
                <flux:text class="whitespace-pre-line wrap-anywhere">{{ $message }}</flux:text>
            </section>

            <div class="flex flex-wrap gap-3">
                <flux:button :icon="$notification->unread() ? 'check' : 'envelope'" wire:click="{{ $notification->unread() ? 'markAsRead' : 'markAsUnread' }}" wire:loading.attr="disabled">{{ $notification->unread() ? 'Marcar como lida' : 'Marcar como não lida' }}</flux:button>
                @if ($destination !== null)
                    <flux:button variant="primary" :href="$destination" icon:trailing="arrow-up-right" wire:navigate>Acessar destino</flux:button>
                @endif
            </div>
            @if ($destination === null)
                <flux:text size="sm">Destino não disponível. O recurso pode ter sido removido ou seu vínculo não tem acesso.</flux:text>
            @endif
            <flux:text size="sm">Abrir este aviso ou acessar seu destino não marca a leitura. A leitura no SGE é independente do envio ou da leitura de e-mails.</flux:text>
        </div>
    </x-loading-overlay>
</div>
