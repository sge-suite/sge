<?php

use App\Actions\UpdateNotificationReadState;
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

new #[Title('Notificações')] class extends Component
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

    public function markAllAsRead(UpdateNotificationReadState $update): void
    {
        $update->markAllAsRead(auth()->user(), $this->affiliationId);
        $this->showMarkAllConfirmation = false;
        $this->refreshNotifications();
    }

    private function refreshNotifications(): void
    {
        app(NotificationInbox::class)->affiliation(auth()->user(), $this->affiliationId);
        unset($this->notifications, $this->unreadCount);
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
}; ?>

<div class="w-full space-y-8">
    <div>
        <flux:heading size="xl" level="1">Notificações</flux:heading>
        <flux:text class="mt-2">Consulte os avisos do vínculo selecionado. Selecione uma linha para abrir os detalhes.</flux:text>
    </div>

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="w-full sm:max-w-xs">
            <x-select wire:model.live="status" label="Leitura" :value="$status" :options="['all' => 'Todas', 'unread' => 'Não lidas', 'read' => 'Lidas']" />
        </div>
        <flux:button icon="check" wire:click="$set('showMarkAllConfirmation', true)" :disabled="$this->unreadCount === 0" wire:loading.attr="disabled">Marcar todas como lidas</flux:button>
    </div>

    <x-loading-overlay target="status,markAllAsRead,gotoPage,nextPage,previousPage" label="Atualizando notificações…" :dim-while-loading="true">
        @php
            $notifications = $this->notifications;
        @endphp
        <div class="space-y-4">
            <flux:text size="sm" role="status" aria-live="polite">{{ $this->unreadCount }} {{ $this->unreadCount === 1 ? 'notificação não lida' : 'notificações não lidas' }} no vínculo selecionado.</flux:text>

            @if ($notifications->isNotEmpty())
                <flux:table :paginate="$notifications" container:class="[&>ui-table-scroll-area]:max-h-[70vh]">
                    <flux:table.columns sticky class="bg-white dark:bg-zinc-800">
                        <flux:table.column>Notificação</flux:table.column>
                        <flux:table.column class="hidden sm:table-cell">Recebida em</flux:table.column>
                        <flux:table.column>Leitura</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($notifications as $notification)
                            @php
                                $data = Arr::wrap($notification->data);
                                $title = is_string($data['title'] ?? null) && trim($data['title']) !== '' ? $data['title'] : 'Notificação';
                            @endphp
                            <flux:table.row :key="'notification-'.$affiliationId.'-'.$notification->id"
                                class="group cursor-pointer transition-colors hover:bg-zinc-100 focus-within:bg-zinc-100 focus:bg-zinc-100 dark:hover:bg-zinc-700 dark:focus-within:bg-zinc-700 dark:focus:bg-zinc-700 {{ $notification->unread() ? 'bg-zinc-50 dark:bg-zinc-900/50' : '' }}"
                                tabindex="0" :aria-label="'Abrir notificação: '.$title"
                                x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-notification-link]').click()"
                                x-on:keydown.enter.prevent="$el.querySelector('[data-notification-link]').click()"
                                x-on:keydown.space.prevent="$el.querySelector('[data-notification-link]').click()">
                                <flux:table.cell class="whitespace-normal">
                                    <a data-notification-link tabindex="-1" href="{{ route('notifications.show', ['notification' => $notification->id]) }}" wire:navigate
                                        @class(['text-zinc-900 underline-offset-4 wrap-anywhere group-hover:underline dark:text-white', 'font-semibold' => $notification->unread(), 'font-medium' => $notification->read()])>{{ $title }}</a>
                                    <flux:text size="sm" class="sm:hidden">{{ formatDateTime($notification->created_at) }}</flux:text>
                                </flux:table.cell>
                                <flux:table.cell class="hidden sm:table-cell">{{ formatDateTime($notification->created_at) }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="$notification->unread() ? 'blue' : 'zinc'" :icon="$notification->unread() ? 'envelope' : 'check'">{{ $notification->unread() ? 'Não lida' : 'Lida' }}</flux:badge>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
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
