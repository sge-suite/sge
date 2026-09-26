<?php

use App\Enums\EmailDeliveryAttemptStatus;
use App\Enums\EmailMessagePurpose;
use App\Models\Affiliation;
use App\Models\EmailDeliveryAttempt;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\CauserResolver;

test('creates attempt schema with optional content, recipient and requester', function () {
    expect(DB::getDriverName())->toBe('pgsql');

    $columns = collect(Schema::getColumns('email_delivery_attempts'))->keyBy('name');
    $indexes = collect(Schema::getIndexes('email_delivery_attempts'));

    expect($columns->get('id'))->toMatchArray(['type' => 'bigint', 'nullable' => false])
        ->and($columns->get('email_message_id'))->toMatchArray(['type' => 'bigint', 'nullable' => true])
        ->and($columns->get('delivery_key'))->toMatchArray(['type' => 'uuid', 'nullable' => false])
        ->and($columns->get('requested_by_affiliation_id'))->toMatchArray(['type' => 'bigint', 'nullable' => true])
        ->and($columns->has('requested_by_user_id'))->toBeFalse()
        ->and($columns->get('recipient_email'))->toMatchArray(['type' => 'text', 'nullable' => false])
        ->and($columns->has('change_context'))->toBeFalse()
        ->and($columns->get('attempt_number'))->toMatchArray(['type' => 'smallint', 'nullable' => false])
        ->and($indexes->firstWhere('columns', ['email_message_id', 'attempt_number'])['unique'])->toBeTrue()
        ->and($indexes->firstWhere('columns', ['delivery_key', 'attempt_number'])['unique'])->toBeTrue()
        ->and($indexes->firstWhere('columns', ['email_message_id', 'status']))->not->toBeNull()
        ->and(collect(Schema::getForeignKeys('email_delivery_attempts'))->count())->toBe(2);
});

test('records invitation delivery without storing message content', function () {
    $recipient = User::factory()->create();
    $attempt = EmailDeliveryAttempt::factory()->accountCreated($recipient)->sent()->create();
    $raw = DB::table('email_delivery_attempts')->where('id', $attempt->id)->first();

    expect($attempt->purpose)->toBe(EmailMessagePurpose::AccountCreated)
        ->and($attempt->email_message_id)->toBeNull()
        ->and($raw->recipient_email)->toBe($recipient->email)
        ->and($attempt->requested_by_affiliation_id)->toBeNull()
        ->and($attempt->sent_at)->not->toBeNull()
        ->and(EmailMessage::query()->count())->toBe(0)
        ->and(Activity::forSubject($attempt)->exists())->toBeFalse();

    expect(fn () => $attempt->delete())->toThrow(ValidationException::class);
});

test('attributes a requested invitation to the active affiliation and account', function () {
    $requester = User::factory()->create();
    $affiliation = Affiliation::factory()->for($requester)->create();
    $recipient = User::factory()->create();

    $attempt = app(CauserResolver::class)->withCauser(
        $affiliation,
        fn (): EmailDeliveryAttempt => EmailDeliveryAttempt::factory()->newAffiliation($recipient)->create(),
    );

    expect($attempt->requested_by_affiliation_id)->toBe($affiliation->id)
        ->and($attempt->requestedByAffiliation->is($affiliation))->toBeTrue()
        ->and($attempt->requestedByAffiliation->user_id)->toBe($requester->id);
});

test('records notification retries and prevents mutation after finalization', function () {
    $affiliation = Affiliation::factory()->student()->create();
    $notification = $affiliation->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'document.available', 'data' => ['title' => 'Documento'],
    ]);
    $message = EmailMessage::factory()->operational($notification)->create();
    $first = EmailDeliveryAttempt::factory()->for($message)->create(['recipient_email' => $affiliation->email]);

    $first->update(['status' => EmailDeliveryAttemptStatus::Failed, 'failed_at' => now(), 'failure_reason' => 'smtp_rejected']);
    $second = EmailDeliveryAttempt::factory()->for($message)->sent()->create([
        'attempt_number' => 2,
        'recipient_email' => $affiliation->email,
    ]);

    expect($message->deliveryAttempts()->count())->toBe(2)
        ->and($first->fresh()->status)->toBe(EmailDeliveryAttemptStatus::Failed)
        ->and($second->status)->toBe(EmailDeliveryAttemptStatus::Sent)
        ->and($second->emailMessage->is($message))->toBeTrue()
        ->and(Activity::forSubject($first)->exists())->toBeFalse();

    expect(fn () => $first->update(['status' => EmailDeliveryAttemptStatus::Sent, 'sent_at' => now()]))
        ->toThrow(ValidationException::class);
});

test('rejects attempts with missing content, mismatched recipients and inactive requesters', function () {
    $recipient = User::factory()->create();
    $requester = User::factory()->create();
    $inactiveAffiliation = Affiliation::factory()->deactivated()->for($requester)->create();

    expect(fn () => EmailDeliveryAttempt::factory()->create([
        'email_message_id' => null,
        'purpose' => EmailMessagePurpose::Notification,
    ]))->toThrow(ValidationException::class);
    expect(fn () => EmailDeliveryAttempt::factory()->newAffiliation($recipient)->create([
        'requested_by_affiliation_id' => $inactiveAffiliation->id,
    ]))->toThrow(ValidationException::class);
    expect(fn () => app(CauserResolver::class)->withCauser(
        $requester,
        fn (): EmailDeliveryAttempt => EmailDeliveryAttempt::factory()->newAffiliation($recipient)->create(),
    ))->toThrow(ValidationException::class);

    $affiliation = Affiliation::factory()->student()->create();
    $notification = $affiliation->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'document.available', 'data' => ['title' => 'Documento'],
    ]);
    $message = EmailMessage::factory()->operational($notification)->create();

    expect(fn () => EmailDeliveryAttempt::factory()->for($message)->create([
        'recipient_email' => 'other@example.test',
    ]))->toThrow(ValidationException::class);
    expect(fn () => EmailDeliveryAttempt::factory()->for($message)->create([
        'recipient_email' => $affiliation->email,
    ])->update(['recipient_email' => 'other@example.test']))->toThrow(ValidationException::class);
});

test('rejects duplicate attempt sequence and unsafe failure text', function () {
    $message = EmailMessage::factory()->create();
    EmailDeliveryAttempt::factory()->for($message)->create();

    expect(fn () => EmailDeliveryAttempt::factory()->for($message)->create())
        ->toThrow(QueryException::class);
    expect(fn () => EmailDeliveryAttempt::factory()->for($message)->failed()->create([
        'attempt_number' => 2,
        'failure_reason' => 'password=secret',
    ]))->toThrow(ValidationException::class);
});

test('stores provider message identifiers as plain text', function () {
    $attempt = EmailDeliveryAttempt::factory()->create(['provider_message_id' => 'smtp-id-123']);

    expect(DB::table('email_delivery_attempts')->where('id', $attempt->id)->value('provider_message_id'))
        ->toBe('smtp-id-123');
});

test('rolls back and reapplies email tables in dependency order', function () {
    $attemptPath = glob(database_path('migrations/*_create_email_delivery_attempts_table.php'))[0];
    $messagePath = glob(database_path('migrations/*_create_email_messages_table.php'))[0];

    foreach ([$attemptPath, $messagePath] as $path) {
        $batch = DB::table('migrations')->where('migration', pathinfo($path, PATHINFO_FILENAME))->value('batch');
        $this->artisan('migrate:rollback', ['--path' => [$path], '--realpath' => true, '--batch' => $batch, '--no-interaction' => true])->assertSuccessful();
    }

    expect(Schema::hasTable('email_delivery_attempts'))->toBeFalse()
        ->and(Schema::hasTable('email_messages'))->toBeFalse()
        ->and(Schema::hasTable('notifications'))->toBeTrue();

    foreach ([$messagePath, $attemptPath] as $path) {
        $this->artisan('migrate', ['--path' => [$path], '--realpath' => true, '--no-interaction' => true])->assertSuccessful();
    }

    expect(Schema::hasTable('email_messages'))->toBeTrue()
        ->and(Schema::hasTable('email_delivery_attempts'))->toBeTrue();
});
