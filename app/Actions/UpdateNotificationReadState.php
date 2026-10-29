<?php

namespace App\Actions;

use App\Models\User;
use App\Support\NotificationInbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Support\CauserResolver;

class UpdateNotificationReadState
{
    private const int CHUNK_SIZE = 100;

    public function __construct(private NotificationInbox $inbox, private CauserResolver $causerResolver) {}

    public function handle(User $actor, int $affiliationId, string $notificationId, bool $read): void
    {
        $affiliation = $this->inbox->affiliation($actor, $affiliationId);
        abort_unless(Str::isUuid($notificationId), 404);

        DB::transaction(function () use ($actor, $affiliationId, $notificationId, $read, $affiliation): void {
            $notification = $this->inbox->query($actor, $affiliationId)->whereKey($notificationId)->lockForUpdate()->firstOrFail();
            $this->causerResolver->withCauser($affiliation, function () use ($notification, $read): void {
                $read ? $notification->markAsRead() : $notification->markAsUnread();
            });
        });
    }

    public function markAllAsRead(User $actor, int $affiliationId): void
    {
        $startedAt = now();
        $this->inbox->query($actor, $affiliationId)->unread()->where('created_at', '<=', $startedAt)
            ->reorder()->select('id')->chunkById(self::CHUNK_SIZE, function ($notifications) use ($actor, $affiliationId): void {
                DB::transaction(function () use ($actor, $affiliationId, $notifications): void {
                    $affiliation = $this->inbox->affiliation($actor, $affiliationId);
                    $unread = $affiliation->notifications()->whereKey($notifications->modelKeys())->unread()
                        ->reorder()->orderBy('id')->lockForUpdate()
                        ->get(['id', 'type', 'notifiable_type', 'notifiable_id', 'read_at', 'created_at', 'updated_at']);

                    $this->causerResolver->withCauser($affiliation, function () use ($unread): void {
                        foreach ($unread as $notification) {
                            $notification->markAsRead();
                        }
                    });
                });
            });
    }
}
