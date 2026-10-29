<?php

use App\Support\NotificationInbox;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $affiliationId;

    #[Locked]
    public string $placement = 'sidebar';

    #[Locked]
    public bool $current;

    public function mount(NotificationInbox $inbox, string $placement = 'sidebar'): void
    {
        $this->affiliationId = $inbox->affiliation(auth()->user())->id;
        $this->placement = $placement;
        $this->current = request()->routeIs('notifications.index');
    }

    public function hydrate(NotificationInbox $inbox): void
    {
        $inbox->affiliation(auth()->user(), $this->affiliationId);
    }

    #[On('notifications-updated')]
    public function refreshCount(NotificationInbox $inbox): void
    {
        $inbox->affiliation(auth()->user(), $this->affiliationId);
        unset($this->unreadCount);
    }

    #[Computed]
    public function unreadCount(): int
    {
        return app(NotificationInbox::class)->query(auth()->user(), $this->affiliationId)->unread()->count();
    }
}; ?>

<div>
    @if ($placement === 'navbar')
        <flux:navbar.item icon="bell" :href="route('notifications.index')" :current="$current" :badge="$this->unreadCount ?: null" :aria-label="'Notificações: '.$this->unreadCount.' não lidas'" wire:navigate>Notificações</flux:navbar.item>
    @else
        <flux:sidebar.item icon="bell" :href="route('notifications.index')" :current="$current" :badge="$this->unreadCount ?: null" :aria-label="'Notificações: '.$this->unreadCount.' não lidas'" wire:navigate>Notificações</flux:sidebar.item>
    @endif
</div>
