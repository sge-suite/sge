<?php

use App\Enums\EmailMessagePurpose;
use App\Models\Affiliation;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

test('creates the email message schema with UUID, foreign keys and encrypted text columns', function () {
    expect(DB::getDriverName())->toBe('pgsql');

    $columns = collect(Schema::getColumns('email_messages'))->keyBy('name');
    $indexes = collect(Schema::getIndexes('email_messages'));

    expect($columns->get('id'))->toMatchArray(['type' => 'bigint', 'nullable' => false])
        ->and($columns->get('notification_id'))->toMatchArray(['type' => 'uuid', 'nullable' => true])
        ->and($columns->get('user_id'))->toMatchArray(['type' => 'bigint', 'nullable' => true])
        ->and($columns->get('affiliation_id'))->toMatchArray(['type' => 'bigint', 'nullable' => true])
        ->and($columns->get('recipient_email')['type'])->toBe('text')
        ->and($columns->get('content_text')['type'])->toBe('text')
        ->and($columns->get('content_html')['type'])->toBe('text')
        ->and($indexes->firstWhere('columns', ['idempotency_key'])['unique'])->toBeTrue()
        ->and(collect(Schema::getForeignKeys('email_messages'))->count())->toBe(3);
});

test('stores a protected immutable account message without persisting access content', function () {
    $user = User::factory()->create();
    $message = EmailMessage::factory()->for($user)->create();
    $raw = DB::table('email_messages')->where('id', $message->id)->first();

    expect($message->id)->toBeInt()
        ->and($message->purpose)->toBe(EmailMessagePurpose::PasswordReset)
        ->and($message->recipient_email)->toBe($user->email)
        ->and($raw->recipient_email)->not->toContain($user->email)
        ->and($message->toArray())->not->toHaveKey('recipient_email')
        ->and($message->content_text)->toBeNull()
        ->and($message->user->is($user))->toBeTrue();

    $user->update(['email' => 'alterado@example.test']);

    expect($message->fresh()->recipient_email)->not->toBe($user->fresh()->email);

    $message->recipient_email = 'novo@example.test';
    expect(fn () => $message->save())->toThrow(ValidationException::class);
});

test('rejects access content in account messages and mismatched recipients', function () {
    $user = User::factory()->create();
    $affiliation = Affiliation::factory()->student()->for($user)->create();
    $wrongNotification = $affiliation->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'document.available', 'data' => ['title' => 'Documento'],
    ]);

    expect(fn () => EmailMessage::factory()->for($user)->create(['content_text' => 'token=secret']))
        ->toThrow(ValidationException::class);
    expect(fn () => EmailMessage::factory()->for($user)->create(['recipient_email' => 'other@example.test']))
        ->toThrow(ValidationException::class);
    expect(fn () => EmailMessage::factory()->for($user)->create(['notification_id' => $wrongNotification->id]))
        ->toThrow(ValidationException::class);
});

test('supports a separate account message for a new affiliation', function () {
    $user = User::factory()->create();
    $message = EmailMessage::factory()->newAffiliation()->for($user)->create();

    expect($message->purpose)->toBe(EmailMessagePurpose::NewAffiliation)
        ->and($message->user_id)->toBe($user->id)
        ->and($message->affiliation_id)->toBeNull()
        ->and($message->recipient_email)->toBe($user->email)
        ->and($message->content_text)->toBeNull();
});

test('links protected operational content only to the notified affiliation', function () {
    $user = User::factory()->create();
    $affiliation = Affiliation::factory()->student()->for($user)->create();
    $otherAffiliation = Affiliation::factory()->supervisor()->for($user)->create();
    $notification = $affiliation->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'document.available', 'data' => ['title' => 'Documento'],
    ]);

    $message = EmailMessage::factory()->operational($affiliation, $notification)->create();
    $raw = DB::table('email_messages')->where('id', $message->id)->first();

    expect($message->affiliation->is($affiliation))->toBeTrue()
        ->and($message->notification->is($notification))->toBeTrue()
        ->and($message->user_id)->toBeNull()
        ->and($raw->recipient_email)->not->toContain($affiliation->email)
        ->and($raw->content_text)->not->toContain('documento')
        ->and($otherAffiliation->notifications()->whereKey($notification->id)->exists())->toBeFalse();

    expect(fn () => EmailMessage::factory()->operational($otherAffiliation, $notification)->create())
        ->toThrow(ValidationException::class);
});

test('enforces one email message per idempotency key', function () {
    $message = EmailMessage::factory()->create();

    expect(fn () => EmailMessage::factory()->create(['idempotency_key' => $message->idempotency_key]))
        ->toThrow(QueryException::class);
});
