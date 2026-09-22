<?php

use App\Enums\EmailDeliveryAttemptStatus;
use App\Models\EmailDeliveryAttempt;
use App\Models\EmailMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

test('creates attempt schema with UUID, foreign key, unique sequence and status index', function () {
    expect(DB::getDriverName())->toBe('pgsql');

    $columns = collect(Schema::getColumns('email_delivery_attempts'))->keyBy('name');
    $indexes = collect(Schema::getIndexes('email_delivery_attempts'));

    expect($columns->get('id'))->toMatchArray(['type' => 'uuid', 'nullable' => false])
        ->and($columns->get('email_message_id'))->toMatchArray(['type' => 'uuid', 'nullable' => false])
        ->and($columns->get('attempt_number'))->toMatchArray(['type' => 'smallint', 'nullable' => false])
        ->and($indexes->firstWhere('columns', ['email_message_id', 'attempt_number'])['unique'])->toBeTrue()
        ->and($indexes->firstWhere('columns', ['email_message_id', 'status']))->not->toBeNull()
        ->and(collect(Schema::getForeignKeys('email_delivery_attempts'))->count())->toBe(1);
});

test('records queued, sent and failed attempts without overwriting prior history', function () {
    $message = EmailMessage::factory()->create();
    $first = EmailDeliveryAttempt::factory()->for($message)->create();

    expect(Str::isUuid($first->id))->toBeTrue()
        ->and($first->status)->toBe(EmailDeliveryAttemptStatus::Queued);

    $first->update(['status' => EmailDeliveryAttemptStatus::Failed, 'failed_at' => now(), 'failure_reason' => 'smtp_rejected']);
    $second = EmailDeliveryAttempt::factory()->for($message)->sent()->create(['attempt_number' => 2]);

    expect($message->deliveryAttempts()->count())->toBe(2)
        ->and($first->fresh()->status)->toBe(EmailDeliveryAttemptStatus::Failed)
        ->and($second->status)->toBe(EmailDeliveryAttemptStatus::Sent)
        ->and($second->emailMessage->is($message))->toBeTrue();

    expect(fn () => $first->update(['status' => EmailDeliveryAttemptStatus::Sent, 'sent_at' => now()]))
        ->toThrow(ValidationException::class);
});

test('rejects duplicate sequence and unsafe failure text', function () {
    $message = EmailMessage::factory()->create();
    EmailDeliveryAttempt::factory()->for($message)->create();

    expect(fn () => EmailDeliveryAttempt::factory()->for($message)->create())
        ->toThrow(QueryException::class);
    expect(fn () => EmailDeliveryAttempt::factory()->for($message)->failed()->create(['attempt_number' => 2, 'failure_reason' => 'password=secret']))
        ->toThrow(ValidationException::class);
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
