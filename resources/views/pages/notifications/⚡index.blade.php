<?php

use App\Actions\UpdateNotificationReadState;
use App\Support\NotificationDestinations;
use App\Support\NotificationInbox;
use App\Support\PageSize;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
/**
 * @property-read LengthAwarePaginator<int, DatabaseNotification> $notifications
 * @property-read array<string, string|null> $destinations
 * @property-read int $unreadCount
 */
#[Title('Notificações')]
class extends Component
{
    use WithPagination;

    #[Locked]
    public int $affiliationId;

    #[Url(except: 'all')]
    public string $status = 'all';

    public bool $showMarkAllConfirmation = false;

    public function mount(NotificationInbox $inbox): void
    {
        $this->affiliationId = $inbox->affiliation(auth()->user())->id;
        $this->normalizeStatus();
    }

    public function hydrate(NotificationInbox $inbox): void
    {
        $inbox->affiliation(auth()->user(), $this->affiliationId);
        $this->normalizeStatus();
    }

    public function updatedStatus(): void
    {
        $this->normalizeStatus();
        $this->resetPage();
    }

    public function markAsRead(string $id, UpdateNotificationReadState $update): void
    {
        $update->handle(auth()->user(), $this->affiliationId, $id, read: true);
        $this->refreshNotifications();
    }

    public function markAsUnread(string $id, UpdateNotificationReadState $update): void
    {
        $update->handle(auth()->user(), $this->affiliationId, $id, read: false);
        $this->refreshNotifications();
    }

    public function markAllAsRead(UpdateNotificationReadState $update): void
    {
        $update->markAllAsRead(auth()->user(), $this->affiliationId);
        $this->showMarkAllConfirmation = false;
        $this->refreshNotifications();
    }

    public function refreshNotifications(): void
    {
        app(NotificationInbox::class)->affiliation(auth()->user(), $this->affiliationId);
        unset($this->notifications, $this->destinations, $this->unreadCount);
        $this->dispatch('notifications-updated');
    }

    private function normalizeStatus(): void
    {
        if (! in_array($this->status, ['all', 'unread', 'read'], true)) {
            $this->status = 'all';
        }
    }

    #[Computed]
    public function unreadCount(): int
    {
        return app(NotificationInbox::class)->query(auth()->user(), $this->affiliationId)->unread()->count();
    }

    /** @return LengthAwarePaginator<int, DatabaseNotification> */
    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        $query = app(NotificationInbox::class)->query(auth()->user(), $this->affiliationId)
            ->when($this->status === 'unread', fn ($query) => $query->unread())
            ->when($this->status === 'read', fn ($query) => $query->read())
            ->orderByDesc('id');
        $notifications = (clone $query)->paginate(PageSize::PER_PAGE);

        if ($notifications->currentPage() > $notifications->lastPage()) {
            $this->setPage($notifications->lastPage());
            $notifications = $query->paginate(PageSize::PER_PAGE);
        }

        return $notifications;
    }

    /** @return array<string, string|null> */
    #[Computed]
    public function destinations(): array
    {
        return app(NotificationDestinations::class)->forPage(auth()->user(), $this->affiliationId, $this->notifications->getCollection());
    }
}; ?>

<div class="w-full space-y-8">
    <div>
        <flux:heading size="xl" level="1">Notificações</flux:heading>
        <flux:text class="mt-2">Acompanhe os avisos do vínculo selecionado e gerencie a leitura no SGE.</flux:text>
    </div>

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="w-full sm:max-w-xs">
            <x-select wire:model.live="status" label="Leitura" :value="$status" :options="['all' => 'Todas', 'unread' => 'Não lidas', 'read' => 'Lidas']" />
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <flux:button icon="arrow-path" wire:click="refreshNotifications" wire:loading.attr="disabled">Atualizar</flux:button>
            <flux:button icon="check" wire:click="$set('showMarkAllConfirmation', true)" :disabled="$this->unreadCount === 0" wire:loading.attr="disabled">Marcar todas como lidas</flux:button>
        </div>
    </div>

    <x-loading-overlay target="status,markAsRead,markAsUnread,markAllAsRead,refreshNotifications,gotoPage,nextPage,previousPage" label="Atualizando notificações…" :dim-while-loading="true">
        @php
            $notifications = $this->notifications;
            $destinations = $this->destinations;
        @endphp
        <div class="space-y-4">
            <flux:text size="sm" role="status" aria-live="polite">{{ $this->unreadCount }} {{ $this->unreadCount === 1 ? 'notificação não lida' : 'notificações não lidas' }} no vínculo selecionado.</flux:text>

            @if ($notifications->isNotEmpty())
                <div class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($notifications as $notification)
                        @php
                            $data = Arr::wrap($notification->data);
                            $title = is_string($data['title'] ?? null) && trim($data['title']) !== '' ? $data['title'] : 'Notificação';
                            $message = is_string($data['message'] ?? null) && trim($data['message']) !== '' ? $data['message'] : 'Este aviso não possui mensagem disponível.';
                            $destination = $destinations[$notification->id] ?? null;
                        @endphp
                        <article wire:key="notification-{{ $affiliationId }}-{{ $notification->id }}" @class(['flex flex-col gap-4 p-5 sm:flex-row sm:items-start sm:justify-between', 'bg-zinc-50 dark:bg-zinc-900/50' => $notification->unread()])>
                            <div class="min-w-0 flex-1 space-y-3">
                                <div class="flex flex-wrap items-start gap-3">
                                    <flux:heading level="2" class="min-w-0 wrap-anywhere">{{ $title }}</flux:heading>
                                    <flux:badge size="sm" :color="$notification->unread() ? 'blue' : 'zinc'" :icon="$notification->unread() ? 'envelope' : 'check'">{{ $notification->unread() ? 'Não lida' : 'Lida' }}</flux:badge>
                                </div>
                                <flux:text class="whitespace-pre-line wrap-anywhere">{{ $message }}</flux:text>
                                <flux:text size="sm">{{ formatDateTime($notification->created_at) }}</flux:text>
                                @if ($destination !== null)
                                    <flux:button size="sm" :href="$destination" icon:trailing="arrow-up-right" wire:navigate>Acessar destino</flux:button>
                                @else
                                    <flux:text size="sm">Destino não disponível. O recurso pode ter sido removido ou seu vínculo não tem acesso.</flux:text>
                                @endif
                            </div>
                            <flux:button size="sm" :icon="$notification->unread() ? 'check' : 'envelope'" wire:click="{{ $notification->unread() ? 'markAsRead' : 'markAsUnread' }}('{{ $notification->id }}')" wire:loading.attr="disabled" :aria-label="($notification->unread() ? 'Marcar como lida: ' : 'Marcar como não lida: ').$title" class="shrink-0 self-start">
                                {{ $notification->unread() ? 'Marcar como lida' : 'Marcar como não lida' }}
                            </flux:button>
                        </article>
                    @endforeach
                </div>
                <flux:pagination :paginator="$notifications" />
            @else
                <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
                    <flux:icon.bell class="mx-auto mb-4 size-8 text-zinc-400" />
                    <flux:heading size="lg">{{ $status === 'all' ? 'Nenhuma notificação' : 'Nenhuma notificação neste filtro' }}</flux:heading>
                    <flux:text class="mt-2">{{ $status === 'all' ? 'Os avisos destinados ao vínculo selecionado aparecerão aqui.' : 'Selecione Todas para consultar os demais avisos do vínculo.' }}</flux:text>
                </div>
            @endif
        </div>
    </x-loading-overlay>

    <flux:modal wire:model="showMarkAllConfirmation" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Marcar todas como lidas?</flux:heading>
                <flux:text class="mt-2">A ação abrange todas as notificações não lidas do vínculo selecionado recebidas até o início da operação, inclusive as que estão em outras páginas.</flux:text>
                <flux:text class="mt-2">Isso altera somente a leitura no SGE e não interfere no envio ou na leitura de e-mails.</flux:text>
            </div>
            <div class="flex flex-wrap justify-end gap-3">
                <flux:modal.close><flux:button>Cancelar</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="markAllAsRead" wire:loading.attr="disabled">Marcar todas como lidas</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
