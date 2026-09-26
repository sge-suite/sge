<?php

use App\Actions\RequestEmailDelivery;
use App\Enums\EmailDeliveryAttemptStatus;
use App\Jobs\SendEmailDelivery;
use App\Mail\DeliveryMail;
use App\Models\Affiliation;
use App\Models\EmailDeliveryAttempt;
use App\Models\EmailMessage;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

test('reserves one queued invitation, dispatches a job and sends without storing its body', function () {
    Bus::fake();
    Mail::fake();
    $requester = Affiliation::factory()->global()->create();
    $key = (string) Str::uuid();

    $attempt = app(CauserResolver::class)->withCauser($requester, fn (): EmailDeliveryAttempt => app(RequestEmailDelivery::class)->invitation('new@example.test', $key));
    $again = app(RequestEmailDelivery::class)->invitation('new@example.test', $key);

    expect($again->is($attempt))->toBeTrue()
        ->and($attempt->status)->toBe(EmailDeliveryAttemptStatus::Queued)
        ->and($attempt->requested_by_affiliation_id)->toBe($requester->id)
        ->and(EmailMessage::query()->count())->toBe(0)
        ->and(EmailDeliveryAttempt::query()->count())->toBe(1);
    Bus::assertDispatched(SendEmailDelivery::class, 1);

    (new SendEmailDelivery($attempt->id))->handle();
    (new SendEmailDelivery($attempt->id))->handle();

    expect($attempt->fresh()->status)->toBe(EmailDeliveryAttemptStatus::Sent)
        ->and($attempt->fresh()->provider)->toBe(config('mail.default'))
        ->and($attempt->fresh()->sent_at)->not->toBeNull();
    Mail::assertSent(DeliveryMail::class, 1);
    Mail::assertSent(DeliveryMail::class, fn (DeliveryMail $mail): bool => $mail->hasTo('new@example.test') &&
        str_contains($mail->render(), 'forgot-password'));
});

test('stores a notification snapshot once and sends its contents to its owner', function () {
    Bus::fake();
    Mail::fake();
    $recipient = Affiliation::factory()->student()->create();
    $notification = $recipient->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'document.available',
        'data' => ['title' => 'Documento disponível'],
    ]);
    $key = (string) Str::uuid();
    $request = app(RequestEmailDelivery::class);

    $attempt = $request->notification($notification, 'Documento disponível', 'Você tem um documento.', '<p>Você tem um documento.</p>', $key);
    $request->notification($notification, 'Documento disponível', 'Você tem um documento.', '<p>Você tem um documento.</p>', $key);
    (new SendEmailDelivery($attempt->id))->handle();

    expect(EmailMessage::query()->count())->toBe(1)
        ->and(EmailDeliveryAttempt::query()->count())->toBe(1)
        ->and($attempt->fresh()->status)->toBe(EmailDeliveryAttemptStatus::Sent);
    Bus::assertDispatched(SendEmailDelivery::class, 1);
    Mail::assertSent(DeliveryMail::class, fn (DeliveryMail $mail): bool => $mail->hasTo($recipient->email) &&
        str_contains($mail->render(), 'Você tem um documento.'));

    expect(fn (): EmailDeliveryAttempt => $request->notification($notification, 'Outro assunto', 'Outro texto', null, $key))
        ->toThrow(ValidationException::class);
});

test('records transport failures without persisting exception text and permits a bounded retry', function () {
    Bus::fake();
    $key = (string) Str::uuid();
    $request = app(RequestEmailDelivery::class);
    $attempt = $request->accountEmailChanged('old@example.test', $key);

    $mailManager = Mail::getFacadeRoot();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp password=secret-token'));
    (new SendEmailDelivery($attempt->id))->handle();

    expect($attempt->fresh()->status)->toBe(EmailDeliveryAttemptStatus::Failed)
        ->and($attempt->fresh()->failure_reason)->toBe('transport_failed')
        ->and($attempt->fresh()->toJson())->not->toContain('secret-token')
        ->and(EmailMessage::query()->exists())->toBeFalse();

    $retry = $request->retry($attempt);
    expect($retry->attempt_number)->toBe(2)
        ->and($retry->delivery_key)->toBe($key)
        ->and($retry->recipient_email)->toBe('old@example.test');
    Bus::assertDispatched(SendEmailDelivery::class, 2);

    Mail::swap($mailManager);
    Mail::fake();
    (new SendEmailDelivery($retry->id))->handle();
    expect($retry->fresh()->status)->toBe(EmailDeliveryAttemptStatus::Sent)
        ->and($request->retry($attempt)->is($retry))->toBeTrue();
    Mail::assertSent(DeliveryMail::class, fn (DeliveryMail $mail): bool => $mail->hasTo('old@example.test') &&
        str_contains($mail->render(), 'endereço de e-mail de acesso'));
});

test('limits reprocessing to three attempts and preserves earlier failures', function () {
    Bus::fake();
    $request = app(RequestEmailDelivery::class);
    $first = $request->invitation('retry@example.test', (string) Str::uuid());

    foreach (range(1, 3) as $number) {
        $current = EmailDeliveryAttempt::query()->where('delivery_key', $first->delivery_key)
            ->where('attempt_number', $number)->sole();
        $current->update([
            'status' => EmailDeliveryAttemptStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => 'transport_failed',
        ]);

        if ($number < 3) {
            $request->retry($first);
        }
    }

    expect(fn (): EmailDeliveryAttempt => $request->retry($first))->toThrow(ValidationException::class)
        ->and(EmailDeliveryAttempt::query()->where('delivery_key', $first->delivery_key)->count())->toBe(3)
        ->and(EmailDeliveryAttempt::query()->where('delivery_key', $first->delivery_key)->where('status', EmailDeliveryAttemptStatus::Failed)->count())->toBe(3);
});

test('rejects delivery keys reused for a different recipient and rolls back a request', function () {
    Bus::fake();
    $request = app(RequestEmailDelivery::class);
    $key = (string) Str::uuid();
    $request->invitation('one@example.test', $key);

    expect(fn (): EmailDeliveryAttempt => $request->invitation('two@example.test', $key))
        ->toThrow(ValidationException::class);

    try {
        DB::transaction(function () use ($request): void {
            $request->accountEmailChanged('rollback@example.test', (string) Str::uuid());
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(EmailDeliveryAttempt::query()->where('recipient_email', 'rollback@example.test')->exists())->toBeFalse();
    Bus::assertDispatched(SendEmailDelivery::class, 2);
});

test('sends a real SMTP message to Mailpit when integration is enabled', function () {
    if (getenv('MAILPIT_INTEGRATION') !== '1') {
        $this->markTestSkipped('Execução opt-in com Mailpit.');
    }

    Bus::fake();
    config()->set('mail.default', 'smtp');
    $attempt = app(RequestEmailDelivery::class)->invitation('sge-mailpit-test@example.test', (string) Str::uuid());

    (new SendEmailDelivery($attempt->id))->handle();

    expect($attempt->fresh()->status)->toBe(EmailDeliveryAttemptStatus::Sent)
        ->and($attempt->fresh()->provider)->toBe('smtp')
        ->and($attempt->fresh()->provider_message_id)->not->toBeNull();
});
