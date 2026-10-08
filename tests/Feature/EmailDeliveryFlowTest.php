<?php

use App\Actions\RequestEmailDelivery;
use App\Enums\AffiliationType;
use App\Enums\EmailDeliveryAttemptStatus;
use App\Enums\EmailMessagePurpose;
use App\Jobs\SendEmailDelivery;
use App\Mail\DeliveryMail;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\EmailDeliveryAttempt;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

test('reserves one account creation invitation and sends its stored content', function () {
    Bus::fake();
    Mail::fake();
    $requester = Affiliation::factory()->global()->create();
    $recipient = User::factory()->create();
    $firstAffiliation = Affiliation::factory()->student()->for($recipient)->create(['email' => $recipient->email]);
    $key = (string) Str::uuid();

    $attempt = app(CauserResolver::class)->withCauser($requester, fn (): EmailDeliveryAttempt => app(RequestEmailDelivery::class)->accountCreated($recipient->email, $key, $firstAffiliation));
    $again = app(CauserResolver::class)->withCauser($requester, fn (): EmailDeliveryAttempt => app(RequestEmailDelivery::class)->accountCreated($recipient->email, $key, $firstAffiliation));

    expect($again->is($attempt))->toBeTrue()
        ->and($attempt->status)->toBe(EmailDeliveryAttemptStatus::Queued)
        ->and($attempt->purpose)->toBe(EmailMessagePurpose::AccountCreated)
        ->and($attempt->requested_by_affiliation_id)->toBe($requester->id)
        ->and(EmailMessage::query()->count())->toBe(1)
        ->and($attempt->emailMessage->purpose)->toBe(EmailMessagePurpose::AccountCreated)
        ->and($attempt->emailMessage->content_text)->toContain('Sua conta foi criada', $firstAffiliation->type->label())
        ->and($attempt->emailMessage->content_html)->toContain(route('password.request', ['email' => $recipient->email]))
        ->and($attempt->emailMessage->content_html)->not->toContain($recipient->password)
        ->and(EmailDeliveryAttempt::query()->count())->toBe(1);
    Bus::assertDispatched(SendEmailDelivery::class, 1);

    (new SendEmailDelivery($attempt->id))->handle();
    (new SendEmailDelivery($attempt->id))->handle();

    expect($attempt->fresh()->status)->toBe(EmailDeliveryAttemptStatus::Sent)
        ->and($attempt->fresh()->provider)->toBe(config('mail.default'))
        ->and($attempt->fresh()->sent_at)->not->toBeNull();
    Mail::assertSent(DeliveryMail::class, 1);
    Mail::assertSent(DeliveryMail::class, fn (DeliveryMail $mail): bool => $mail->hasTo($recipient->email) &&
        str_contains($mail->render(), 'forgot-password'));

    (new DeliveryMail($attempt))->assertSeeInHtml('Sua conta foi criada')
        ->assertSeeInHtml($firstAffiliation->type->label())
        ->assertSeeInText('Sua conta foi criada')
        ->assertSeeInText($firstAffiliation->type->label());
});

test('snapshots the new affiliation details into each login notice', function () {
    Bus::fake();
    $user = User::factory()->create();
    $campus = Campus::factory()->create(['name' => 'INSTITUTO FEDERAL FARROUPILHA - CAMPUS SANTO AUGUSTO']);
    $affiliation = Affiliation::factory()->for($user)->create([
        'type' => AffiliationType::CampusAdministrator,
        'campus_id' => $campus->id,
        'course_id' => null,
        'registration_number' => 'CA-2026-117',
    ]);
    $requester = Affiliation::factory()->global()->create();
    $key = (string) Str::uuid();

    $attempt = app(CauserResolver::class)->withCauser(
        $requester,
        fn (): EmailDeliveryAttempt => app(RequestEmailDelivery::class)->affiliationCreated($user->email, $affiliation, $key),
    );
    $again = app(CauserResolver::class)->withCauser($requester, fn (): EmailDeliveryAttempt => app(RequestEmailDelivery::class)->affiliationCreated($user->email, $affiliation, $key));

    expect($attempt->purpose)->toBe(EmailMessagePurpose::NewAffiliation)
        ->and($attempt->email_message_id)->not->toBeNull()
        ->and($again->is($attempt))->toBeTrue()
        ->and(EmailMessage::query()->count())->toBe(1)
        ->and($attempt->emailMessage->content_text)->toContain($affiliation->type->label(), $campus->name, $affiliation->registration_number);

    $differentAffiliation = Affiliation::factory()->global()->for($user)->create();
    expect(fn (): EmailDeliveryAttempt => app(RequestEmailDelivery::class)->affiliationCreated($user->email, $differentAffiliation, $key))
        ->toThrow(ValidationException::class);

    (new DeliveryMail($attempt))->assertSeeInHtml('Novo vínculo criado')
        ->assertSeeInHtml($affiliation->type->label())
        ->assertSeeInHtml($campus->name)
        ->assertSeeInHtml($affiliation->registration_number)
        ->assertSeeInHtml(route('login'))
        ->assertSeeInText('Novo vínculo criado')
        ->assertSeeInText($affiliation->type->label())
        ->assertSeeInText($campus->name)
        ->assertSeeInText($affiliation->registration_number)
        ->assertSeeInText(route('login'))
        ->assertDontSeeInHtml(route('password.request'));
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

    (new DeliveryMail($attempt->fresh(['emailMessage'])))->assertSeeInHtml('Você tem um documento.')
        ->assertSeeInText('Você tem um documento.');

    expect(fn (): EmailDeliveryAttempt => $request->notification($notification, 'Outro assunto', 'Outro texto', null, $key))
        ->toThrow(ValidationException::class);
});

test('records transport failures without persisting exception text and permits a bounded retry', function () {
    Bus::fake();
    $key = (string) Str::uuid();
    $request = app(RequestEmailDelivery::class);
    $attempt = $request->accountEmailChanged('old@example.test', 'old@example.test', 'new@example.test', $key);

    $mailManager = Mail::getFacadeRoot();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp password=secret-token'));
    (new SendEmailDelivery($attempt->id))->handle();

    expect($attempt->fresh()->status)->toBe(EmailDeliveryAttemptStatus::Failed)
        ->and($attempt->fresh()->failure_reason)->toBe('transport_failed')
        ->and($attempt->fresh()->toJson())->not->toContain('secret-token')
        ->and(EmailMessage::query()->count())->toBe(1);

    $retry = $request->retry($attempt);
    expect($retry->attempt_number)->toBe(2)
        ->and($retry->delivery_key)->toBe($key)
        ->and($retry->recipient_email)->toBe('old@example.test')
        ->and($retry->email_message_id)->toBe($attempt->email_message_id);
    Bus::assertDispatched(SendEmailDelivery::class, 2);

    Mail::swap($mailManager);
    Mail::fake();
    (new SendEmailDelivery($retry->id))->handle();
    expect($retry->fresh()->status)->toBe(EmailDeliveryAttemptStatus::Sent)
        ->and($request->retry($attempt)->is($retry))->toBeTrue();
    Mail::assertSent(DeliveryMail::class, fn (DeliveryMail $mail): bool => $mail->hasTo('old@example.test') &&
        str_contains($mail->render(), 'old@example.test') && str_contains($mail->render(), 'new@example.test'));

    (new DeliveryMail($retry))->assertSeeInHtml('old@example.test')
        ->assertSeeInHtml('new@example.test')
        ->assertSeeInText('old@example.test')
        ->assertSeeInText('new@example.test');
});

test('email change content is stable for repeated requests and immutable after reservation', function () {
    Bus::fake();
    $request = app(RequestEmailDelivery::class);
    $key = (string) Str::uuid();
    $attempt = $request->accountEmailChanged('old@example.test', 'old@example.test', 'new@example.test', $key);

    expect($request->accountEmailChanged('old@example.test', 'old@example.test', 'new@example.test', $key)->is($attempt))->toBeTrue()
        ->and(fn (): EmailDeliveryAttempt => $request->accountEmailChanged('old@example.test', 'old@example.test', 'different@example.test', $key))
        ->toThrow(ValidationException::class)
        ->and(fn (): bool => $attempt->emailMessage->update(['content_text' => 'alterado']))
        ->toThrow(ValidationException::class);

    expect($attempt->fresh()->emailMessage->content_text)->toContain('old@example.test', 'new@example.test');
    Bus::assertDispatched(SendEmailDelivery::class, 1);
});

test('administrative email signature snapshots the requester and falls back to the system', function (string $purpose) {
    Bus::fake();
    $requester = Affiliation::factory()->global()->create();
    $requester->user->update(['name' => 'Solicitante original']);
    $target = Affiliation::factory()->global()->create();
    $request = app(RequestEmailDelivery::class);
    $reserve = fn (): EmailDeliveryAttempt => match ($purpose) {
        'account_created' => $request->accountCreated($target->email, (string) Str::uuid(), $target),
        'new_affiliation' => $request->affiliationCreated($target->email, $target, (string) Str::uuid()),
        'account_email_changed' => $request->accountEmailChanged($target->email, $target->email, 'new@example.test', (string) Str::uuid(), $target->user),
        'administrative_change' => $request->administrativeChange($target->email, 'Aviso administrativo', 'Mensagem de exemplo.', (string) Str::uuid(), $target),
    };
    $attempt = app(CauserResolver::class)->withCauser($requester, $reserve);
    $message = $attempt->emailMessage;

    expect($message->content_html)->toContain('Solicitante original', 'Administrador do Sistema', 'via '.config('app.name'))
        ->and($message->content_text)->toContain('Solicitante original', 'Administrador do Sistema', 'via '.config('app.name'));
    $requester->user->update(['name' => 'Solicitante renomeado']);
    $attempt->update(['status' => EmailDeliveryAttemptStatus::Failed, 'failed_at' => now(), 'failure_reason' => 'transport_failed']);
    $retry = $request->retry($attempt);
    expect($retry->email_message_id)->toBe($message->id);
    (new DeliveryMail($retry))->assertSeeInHtml('Solicitante original')->assertSeeInText('Solicitante original')
        ->assertDontSeeInHtml('Solicitante renomeado');

    $automatic = $reserve()->emailMessage;
    expect($automatic->content_html)->toContain('Atenciosamente', config('app.name'))
        ->not->toContain('Solicitante original', 'Solicitante renomeado', 'via '.config('app.name'))
        ->and($automatic->content_text)->not->toContain('via '.config('app.name'));
})->with(['account_created', 'new_affiliation', 'account_email_changed', 'administrative_change']);

test('account invitation preserves its content after account changes and on retry', function () {
    Bus::fake();
    $affiliation = Affiliation::factory()->global()->create();
    $user = $affiliation->user;
    $originalEmail = $user->email;
    $request = app(RequestEmailDelivery::class);
    $attempt = $request->accountCreated($originalEmail, (string) Str::uuid(), $affiliation);
    $message = $attempt->emailMessage;
    $user->update(['email' => 'changed@example.test']);
    $attempt->update(['status' => EmailDeliveryAttemptStatus::Failed, 'failed_at' => now(), 'failure_reason' => 'transport_failed']);
    $retry = $request->retry($attempt);

    expect($retry->email_message_id)->toBe($message->id)
        ->and($retry->recipient_email)->toBe($originalEmail)
        ->and($message->content_text)->toContain(route('password.request', ['email' => $originalEmail]))
        ->and(fn (): bool => $message->update(['content_text' => 'alterado']))->toThrow(ValidationException::class);
    (new DeliveryMail($retry))->assertSeeInHtml(route('password.request', ['email' => $originalEmail]))
        ->assertSeeInText('Administrador do Sistema')->assertDontSeeInHtml('changed@example.test');
});

test('limits reprocessing to three attempts and preserves earlier failures', function () {
    Bus::fake();
    $request = app(RequestEmailDelivery::class);
    $first = $request->accountCreated('retry@example.test', (string) Str::uuid());

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
    $request->accountCreated('one@example.test', $key);

    expect(fn (): EmailDeliveryAttempt => $request->accountCreated('two@example.test', $key))
        ->toThrow(ValidationException::class);

    try {
        DB::transaction(function () use ($request): void {
            $request->accountEmailChanged('rollback@example.test', 'rollback@example.test', 'new@example.test', (string) Str::uuid());
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(EmailDeliveryAttempt::query()->where('recipient_email', 'rollback@example.test')->exists())->toBeFalse();
    Bus::assertDispatched(SendEmailDelivery::class, 2);
});
