<?php

use App\Enums\EmailMessagePurpose;
use App\Models\Affiliation;
use App\Models\EmailMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

test('creates a content table without recipient or requester fields', function () {
    expect(DB::getDriverName())->toBe('pgsql');

    $columns = collect(Schema::getColumns('email_messages'))->keyBy('name');
    $indexes = collect(Schema::getIndexes('email_messages'));

    expect($columns->keys()->all())->toBe([
        'id', 'notification_id', 'purpose', 'subject', 'content_text', 'content_html',
        'template_key', 'template_version', 'idempotency_key', 'created_at', 'updated_at',
    ])
        ->and($columns->get('notification_id'))->toMatchArray(['type' => 'uuid', 'nullable' => true])
        ->and($columns->get('content_text')['type'])->toBe('text')
        ->and($columns->get('content_html')['type'])->toBe('text')
        ->and($indexes->firstWhere('columns', ['idempotency_key'])['unique'])->toBeTrue()
        ->and(collect(Schema::getForeignKeys('email_messages'))->count())->toBe(1);
});

test('stores immutable notification content without duplicating recipient or activity log', function () {
    $affiliation = Affiliation::factory()->student()->create();
    $notification = $affiliation->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'document.available', 'data' => ['title' => 'Documento'],
    ]);
    $message = EmailMessage::factory()->operational($notification)->create();
    $raw = DB::table('email_messages')->where('id', $message->id)->first();

    expect($message->purpose)->toBe(EmailMessagePurpose::Notification)
        ->and($message->notification->is($notification))->toBeTrue()
        ->and($raw->subject)->toBe('Documento disponível')
        ->and($raw->content_text)->toBe('Um documento está disponível para análise.')
        ->and($raw->content_html)->toBe('<p>Um documento está disponível para análise.</p>')
        ->and($message->toArray())->not->toHaveKeys(['subject', 'content_text', 'content_html'])
        ->and(Activity::forSubject($message)->exists())->toBeFalse();

    expect(fn () => $message->update(['subject' => 'Alterado']))->toThrow(ValidationException::class);
    expect(fn () => $message->delete())->toThrow(ValidationException::class);
});

test('rejects contentless messages and invitation snapshots', function () {
    expect(fn () => EmailMessage::factory()->create(['content_text' => null, 'content_html' => null]))
        ->toThrow(ValidationException::class);
    expect(fn () => EmailMessage::factory()->create(['purpose' => EmailMessagePurpose::NewAffiliation]))
        ->toThrow(ValidationException::class);
});

test('enforces one content snapshot per idempotency key', function () {
    $message = EmailMessage::factory()->create();

    expect(fn () => EmailMessage::factory()->create(['idempotency_key' => $message->idempotency_key]))
        ->toThrow(QueryException::class);
});
