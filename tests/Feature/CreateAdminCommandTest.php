<?php

use App\Enums\AffiliationType;
use App\Enums\EmailMessagePurpose;
use App\Jobs\SendEmailDelivery;
use App\Mail\DeliveryMail;
use App\Models\Affiliation;
use App\Models\EmailDeliveryAttempt;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\PendingCommand;
use Spatie\Activitylog\Models\Activity;

function expectNewAdminPrompts(PendingCommand $command, string $cpf = '52998224725', string $email = 'ADA@EXAMPLE.TEST', bool $confirm = true): PendingCommand
{
    return $command
        ->expectsQuestion('CPF (11 dígitos, somente números)', $cpf)
        ->expectsQuestion('Nome completo', 'Ada Lovelace')
        ->expectsQuestion('E-mail da conta', $email)
        ->expectsQuestion('Número de registro institucional', 'ADM-001')
        ->expectsQuestion('Criar esta conta e seu vínculo administrador?', $confirm);
}

function expectExistingAdminPrompts(PendingCommand $command, bool $confirm = true, string $email = 'ADMIN@EXAMPLE.TEST'): PendingCommand
{
    return $command
        ->expectsQuestion('CPF (11 dígitos, somente números)', '52998224725')
        ->expectsQuestion('E-mail do vínculo', $email)
        ->expectsQuestion('Número de registro institucional', 'ADM-007')
        ->expectsQuestion('Adicionar o vínculo administrador a esta conta?', $confirm);
}

beforeEach(function (): void {
    Bus::fake();
});

test('creates the initial system administrator with a shared email and queues an account invitation', function () {
    Mail::fake();

    expectNewAdminPrompts($this->artisan('admin:create'))
        ->assertSuccessful();

    $user = User::query()->sole();
    $affiliation = Affiliation::query()->sole();

    expect($user->name)->toBe('Ada Lovelace')
        ->and($user->cpf)->toBe('52998224725')
        ->and($user->email)->toBe('ada@example.test')
        ->and(Hash::needsRehash($user->getRawOriginal('password')))->toBeFalse()
        ->and($affiliation->user_id)->toBe($user->id)
        ->and($affiliation->email)->toBe($user->email)
        ->and($affiliation->type)->toBe(AffiliationType::SystemAdministrator)
        ->and($affiliation->campus_id)->toBeNull()
        ->and($affiliation->course_id)->toBeNull()
        ->and($affiliation->registration_number)->toBe('ADM-001')
        ->and(EmailMessage::query()->exists())->toBeFalse()
        ->and(EmailDeliveryAttempt::query()->count())->toBe(1);

    $attempt = EmailDeliveryAttempt::query()->sole();
    expect($attempt->recipient_email)->toBe($user->email)
        ->and($attempt->purpose)->toBe(EmailMessagePurpose::AccountCreated)
        ->and($attempt->email_message_id)->toBeNull();
    (new DeliveryMail($attempt))->assertSeeInHtml('Sua conta foi criada')
        ->assertSeeInHtml('Administrador do Sistema')
        ->assertSeeInHtml(route('password.request', ['email' => $user->email]))
        ->assertSeeInText('Sua conta foi criada')
        ->assertSeeInText('Administrador do Sistema');
    Bus::assertDispatched(SendEmailDelivery::class, 1);

    $activities = Activity::query()->get()->toJson();
    expect($activities)->not->toContain($user->getRawOriginal('password'));
    expect(Activity::forSubject($user)->where('event', 'created')->sole()->properties->get('actor'))
        ->toBe('terminal')
        ->and(Activity::forSubject($affiliation)->where('event', 'created')->sole()->properties->get('actor'))
        ->toBe('terminal');
    Mail::assertNothingOutgoing();
});

test('adds an administrator affiliation and queues notices to the account and affiliation emails', function () {
    Mail::fake();
    $user = User::factory()->create([
        'name' => 'Ada Lovelace',
        'cpf' => '52998224725',
        'email' => 'ada@example.test',
    ]);
    $passwordHash = $user->getRawOriginal('password');
    $userActivityCount = Activity::forSubject($user)->count();

    expectExistingAdminPrompts($this->artisan('admin:create'))->assertSuccessful();

    $user->refresh();
    $affiliation = Affiliation::query()->whereBelongsTo($user)->sole();

    expect(User::query()->count())->toBe(1)
        ->and($user->name)->toBe('Ada Lovelace')
        ->and($user->email)->toBe('ada@example.test')
        ->and($user->getRawOriginal('password'))->toBe($passwordHash)
        ->and($affiliation->email)->toBe('admin@example.test')
        ->and($affiliation->registration_number)->toBe('ADM-007')
        ->and($affiliation->type)->toBe(AffiliationType::SystemAdministrator)
        ->and($affiliation->deactivated_at)->toBeNull()
        ->and($affiliation->campus_id)->toBeNull()
        ->and($affiliation->course_id)->toBeNull()
        ->and(Activity::forSubject($user)->count())->toBe($userActivityCount)
        ->and(Activity::forSubject($affiliation)->where('event', 'created')->sole()->properties->get('actor'))->toBe('terminal');

    $attempts = EmailDeliveryAttempt::query()->orderBy('recipient_email')->get();
    expect($attempts)->toHaveCount(2)
        ->and($attempts->pluck('recipient_email')->all())->toBe(['ada@example.test', 'admin@example.test'])
        ->and($attempts->pluck('purpose')->unique()->sole())->toBe(EmailMessagePurpose::NewAffiliation)
        ->and($attempts->every(fn (EmailDeliveryAttempt $attempt): bool => $attempt->email_message_id === null))->toBeTrue();

    foreach ($attempts as $attempt) {
        (new DeliveryMail($attempt))->assertSeeInHtml('Novo vínculo criado')
            ->assertSeeInHtml(route('login'))
            ->assertSeeInText('Novo vínculo criado')
            ->assertSeeInText(route('login'));
    }

    Bus::assertDispatched(SendEmailDelivery::class, 2);
    Mail::assertNothingOutgoing();
});

test('allows normal accounts and inactive system administrators to exist before bootstrap', function () {
    Bus::fake();
    User::factory()->create();
    $inactiveAdministrator = User::factory()->create();
    Affiliation::factory()->global()->deactivated()->for($inactiveAdministrator)->create();

    expectNewAdminPrompts($this->artisan('admin:create'))->assertSuccessful();

    expect(Affiliation::query()->active()->where('type', AffiliationType::SystemAdministrator->value)->count())
        ->toBe(1)
        ->and(User::query()->count())->toBe(3);
});

test('adds a new active administrator affiliation to an existing user with an inactive one', function () {
    $user = User::factory()->create(['cpf' => '52998224725']);
    Affiliation::factory()->global()->deactivated()->for($user)->create();

    expectExistingAdminPrompts($this->artisan('admin:create'))->assertSuccessful();

    expect(User::query()->count())->toBe(1)
        ->and($user->affiliations()->count())->toBe(2)
        ->and($user->affiliations()->active()->sole()->email)->toBe('admin@example.test');
});

test('sends one affiliation notice when the account and affiliation emails match', function () {
    $user = User::factory()->create([
        'cpf' => '52998224725',
        'email' => 'admin@example.test',
    ]);

    expectExistingAdminPrompts($this->artisan('admin:create'), email: 'ADMIN@EXAMPLE.TEST')->assertSuccessful();

    expect(EmailDeliveryAttempt::query()->count())->toBe(1)
        ->and(EmailDeliveryAttempt::query()->sole()->recipient_email)->toBe($user->email);
    Bus::assertDispatched(SendEmailDelivery::class, 1);
});

test('allows creating another administrator when an active system administrator already exists', function () {
    $user = User::factory()->create();
    Affiliation::factory()->global()->for($user)->create();

    expectNewAdminPrompts($this->artisan('admin:create'))->assertSuccessful();

    expect(User::query()->count())->toBe(2)
        ->and(Affiliation::query()->active()->where('type', AffiliationType::SystemAdministrator->value)->count())
        ->toBe(2);
});

test('rejects an invalid or formatted cpf before asking for account data', function (string $cpf) {
    $this->artisan('admin:create')
        ->expectsQuestion('CPF (11 dígitos, somente números)', $cpf)
        ->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(Affiliation::query()->count())->toBe(0);
})->with(['529.982.247-25', '00000000000', '123']);

test('rejects an account email already used by another user', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->artisan('admin:create')
        ->expectsQuestion('CPF (11 dígitos, somente números)', '52998224725')
        ->expectsQuestion('Nome completo', 'Ada Lovelace')
        ->expectsQuestion('E-mail da conta', 'TAKEN@EXAMPLE.TEST')
        ->assertFailed();

    expect(User::query()->count())->toBe(1)
        ->and(Affiliation::query()->count())->toBe(0);
});

test('rejects an email without a complete domain and accepts a corrected address', function () {
    $command = $this->artisan('admin:create')
        ->expectsQuestion('CPF (11 dígitos, somente números)', '52998224725')
        ->expectsQuestion('Nome completo', 'Ada Lovelace')
        ->expectsQuestion('E-mail da conta', 'arthur2008willers@g')
        ->expectsQuestion('E-mail da conta', 'arthur2008willers@gmail.com')
        ->expectsQuestion('Número de registro institucional', 'ADM-001')
        ->expectsQuestion('Criar esta conta e seu vínculo administrador?', true);

    $command->assertSuccessful();

    expect(User::query()->sole()->email)->toBe('arthur2008willers@gmail.com');
});

test('rejects an invalid affiliation email without changing the existing user', function () {
    $user = User::factory()->create(['cpf' => '52998224725']);

    $this->artisan('admin:create')
        ->expectsQuestion('CPF (11 dígitos, somente números)', '52998224725')
        ->expectsQuestion('E-mail do vínculo', 'not-an-email')
        ->assertFailed();

    expect(User::query()->count())->toBe(1)
        ->and($user->affiliations()->count())->toBe(0);
});

test('does not create records when the operator declines the final confirmation', function () {
    Bus::fake();

    expectNewAdminPrompts($this->artisan('admin:create'), confirm: false)->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(Affiliation::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

test('does not add a link when the operator cancels an existing account', function () {
    $user = User::factory()->create(['cpf' => '52998224725']);
    $activityCount = Activity::query()->count();

    expectExistingAdminPrompts($this->artisan('admin:create'), confirm: false)->assertFailed();

    expect(User::query()->count())->toBe(1)
        ->and($user->affiliations()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe($activityCount);
    Bus::assertNothingDispatched();
});

test('rejects non-interactive execution without writing records', function () {
    $this->artisan('admin:create', ['--no-interaction' => true])->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(Affiliation::query()->count())->toBe(0);
});

test('rolls back the account and its activity if affiliation creation fails', function () {
    Bus::fake();
    Event::listen('eloquent.creating: '.Affiliation::class, static function (Affiliation $affiliation): void {
        throw new RuntimeException('Falha simulada.');
    });

    expectNewAdminPrompts($this->artisan('admin:create'))->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(Affiliation::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

test('preserves an existing account when affiliation creation fails', function () {
    $user = User::factory()->create(['cpf' => '52998224725']);
    $passwordHash = $user->getRawOriginal('password');
    $activityCount = Activity::query()->count();
    Event::listen('eloquent.creating: '.Affiliation::class, static function (Affiliation $affiliation): void {
        throw new RuntimeException('Falha simulada.');
    });

    expectExistingAdminPrompts($this->artisan('admin:create'))->assertFailed();

    expect(User::query()->count())->toBe(1)
        ->and($user->fresh()->getRawOriginal('password'))->toBe($passwordHash)
        ->and(Affiliation::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe($activityCount);
    Bus::assertNothingDispatched();
});
