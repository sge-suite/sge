<?php

use App\Models\Affiliation;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('creates the native notifications schema with PostgreSQL jsonb data and UUIDs', function () {
    expect(DB::getDriverName())->toBe('pgsql');

    $columns = collect(Schema::getColumns('notifications'))->keyBy('name');

    expect($columns->keys()->all())->toBe([
        'id',
        'type',
        'notifiable_type',
        'notifiable_id',
        'data',
        'read_at',
        'created_at',
        'updated_at',
    ]);

    foreach ([
        'id' => ['uuid', false],
        'type' => ['character varying(255)', false],
        'notifiable_type' => ['character varying(255)', false],
        'notifiable_id' => ['bigint', false],
        'data' => ['jsonb', false],
        'read_at' => ['timestamp(0) without time zone', true],
        'created_at' => ['timestamp(0) without time zone', true],
        'updated_at' => ['timestamp(0) without time zone', true],
    ] as $name => [$type, $nullable]) {
        expect($columns->get($name))->toMatchArray(['type' => $type, 'nullable' => $nullable]);
    }

    $indexes = collect(Schema::getIndexes('notifications'));

    expect($indexes->firstWhere('primary', true))->toMatchArray([
        'columns' => ['id'], 'primary' => true, 'unique' => true,
    ])
        ->and($indexes->firstWhere('columns', ['notifiable_type', 'notifiable_id']))->not->toBeNull()
        ->and(Schema::getForeignKeys('notifications'))->toBeEmpty()
        ->and(Schema::hasColumn('notifications', 'affiliation_id'))->toBeFalse()
        ->and(Schema::hasColumn('notifications', 'deduplication_key'))->toBeFalse();
});

test('rolls back and reapplies only the notifications migration on PostgreSQL', function () {
    $paths = glob(database_path('migrations/*_create_notifications_table.php'));
    expect($paths)->toHaveCount(1);
    $options = ['--path' => $paths, '--realpath' => true, '--no-interaction' => true];
    $batch = DB::table('migrations')
        ->where('migration', pathinfo($paths[0], PATHINFO_FILENAME))
        ->value('batch');

    $this->artisan('migrate:rollback', [...$options, '--batch' => $batch])->assertSuccessful();
    expect(Schema::hasTable('notifications'))->toBeFalse()
        ->and(Schema::hasTable('affiliations'))->toBeTrue()
        ->and(Schema::hasTable('users'))->toBeTrue();

    $this->artisan('migrate', $options)->assertSuccessful();
    expect(Schema::hasTable('notifications'))->toBeTrue();
});

test('sends database notifications to an affiliation through the native polymorphic relation', function () {
    $affiliation = Affiliation::factory()->student()->create();
    $payload = ['title' => 'Documento disponível', 'route' => 'internships.documents'];
    $notification = new class($payload) extends Notification
    {
        public function __construct(private array $payload) {}

        public function via(object $notifiable): array
        {
            return ['database'];
        }

        public function toDatabase(object $notifiable): array
        {
            return $this->payload;
        }

        public function databaseType(object $notifiable): string
        {
            return 'internship.document-available';
        }
    };

    $affiliation->notify($notification);
    $storedNotification = $affiliation->notifications()->sole();

    expect(Str::isUuid($storedNotification->id))->toBeTrue()
        ->and($storedNotification->type)->toBe('internship.document-available')
        ->and($storedNotification->notifiable_type)->toBe(Affiliation::class)
        ->and($storedNotification->notifiable_id)->toBe($affiliation->id)
        ->and($storedNotification->data)->toEqual($payload)
        ->and($storedNotification->read_at)->toBeNull();
});

test('tracks notification read and unread state with the native database notification model', function () {
    $affiliation = Affiliation::factory()->student()->create();
    $notification = new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        public function toDatabase(object $notifiable): array
        {
            return ['title' => 'Ação necessária'];
        }
    };

    $affiliation->notify($notification);
    $storedNotification = $affiliation->unreadNotifications()->sole();

    expect($storedNotification->read_at)->toBeNull()
        ->and($affiliation->readNotifications()->count())->toBe(0);

    $storedNotification->markAsRead();

    expect($affiliation->unreadNotifications()->count())->toBe(0)
        ->and($affiliation->readNotifications()->sole()->read_at)->not->toBeNull();
});

test('keeps operational notifications isolated between two affiliations of the same account', function () {
    $user = User::factory()->create();
    $firstAffiliation = Affiliation::factory()->student()->for($user)->create();
    $secondAffiliation = Affiliation::factory()->supervisor()->for($user)->create();
    $createNotification = fn (): Notification => new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        public function toDatabase(object $notifiable): array
        {
            return ['title' => 'Aviso operacional'];
        }
    };

    $firstAffiliation->notify($createNotification());
    $secondAffiliation->notify($createNotification());
    $firstNotificationId = $firstAffiliation->notifications()->sole()->id;
    $secondNotificationId = $secondAffiliation->notifications()->sole()->id;

    expect($firstAffiliation->user_id)->toBe($secondAffiliation->user_id)
        ->and($firstAffiliation->notifications()->whereKey($firstNotificationId)->exists())->toBeTrue()
        ->and($firstAffiliation->notifications()->whereKey($secondNotificationId)->exists())->toBeFalse()
        ->and($secondAffiliation->notifications()->whereKey($firstNotificationId)->exists())->toBeFalse()
        ->and($secondAffiliation->notifications()->whereKey($secondNotificationId)->exists())->toBeTrue();
});

test('keeps account notifications destined to User on the native user relationship', function () {
    $user = User::factory()->create();
    $affiliation = Affiliation::factory()->student()->for($user)->create();
    $notification = new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        public function toDatabase(object $notifiable): array
        {
            return ['title' => 'Aviso de conta'];
        }

        public function databaseType(object $notifiable): string
        {
            return 'account.initial-email';
        }
    };

    $user->notify($notification);
    $storedNotification = $user->notifications()->sole();

    expect($storedNotification->notifiable_type)->toBe(User::class)
        ->and($storedNotification->notifiable_id)->toBe($user->id)
        ->and($storedNotification->type)->toBe('account.initial-email')
        ->and($storedNotification->data)->toBe(['title' => 'Aviso de conta'])
        ->and($user->unreadNotifications()->count())->toBe(1)
        ->and($affiliation->notifications()->count())->toBe(0);

    $storedNotification->markAsRead();

    expect($user->unreadNotifications()->count())->toBe(0)
        ->and($user->readNotifications()->sole()->id)->toBe($storedNotification->id);
});

test('authorizes the notification inbox only for an active affiliation owned by the account', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $activeAffiliation = Affiliation::factory()->student()->for($user)->create();
    $deactivatedAffiliation = Affiliation::factory()->student()->deactivated()->for($user)->create();
    $otherUsersAffiliation = Affiliation::factory()->student()->for($otherUser)->create();

    expect(Gate::forUser($user)->allows('viewNotifications', $activeAffiliation))->toBeTrue()
        ->and(Gate::forUser($user)->allows('viewNotifications', $deactivatedAffiliation))->toBeFalse()
        ->and(Gate::forUser($user)->allows('viewNotifications', $otherUsersAffiliation))->toBeFalse();
});
