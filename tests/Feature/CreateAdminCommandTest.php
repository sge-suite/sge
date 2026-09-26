<?php

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\EmailDeliveryAttempt;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\PendingCommand;
use Spatie\Activitylog\Models\Activity;

function expectNewAdminPrompts(PendingCommand $command, string $cpf = '52998224725', string $email = 'ADA@EXAMPLE.TEST', bool $confirm = true): PendingCommand
{
    return $command
        ->expectsQuestion('CPF (11 dígitos, somente números)', $cpf)
        ->expectsQuestion('Nome completo', 'Ada Lovelace')
        ->expectsQuestion('E-mail da conta', $email)
        ->expectsQuestion('Número de registro institucional', 'ADM-001')
        ->expectsOutputToContain('Regras da senha')
        ->expectsQuestion('Senha inicial', 'SgeInitialPassword9!a')
        ->expectsQuestion('Confirme a senha', 'SgeInitialPassword9!a')
        ->expectsQuestion('Criar esta conta e seu vínculo administrador?', $confirm);
}

function expectExistingAdminPrompts(PendingCommand $command, bool $confirm = true): PendingCommand
{
    return $command
        ->expectsQuestion('CPF (11 dígitos, somente números)', '52998224725')
        ->expectsQuestion('E-mail do vínculo', 'ADMIN@EXAMPLE.TEST')
        ->expectsQuestion('Número de registro institucional', 'ADM-007')
        ->expectsQuestion('Adicionar o vínculo administrador a esta conta?', $confirm);
}

function fakePasswordBreachCheck(): void
{
    Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::response('')]);
    Http::preventStrayRequests();
}

test('creates the initial system administrator with a shared email and a hashed password', function () {
    fakePasswordBreachCheck();
    Mail::fake();
    Notification::fake();
    $password = 'SgeInitialPassword9!a';

    expectNewAdminPrompts($this->artisan('admin:create'))
        ->doesntExpectOutputToContain($password)
        ->assertSuccessful();

    $user = User::query()->sole();
    $affiliation = Affiliation::query()->sole();

    expect($user->name)->toBe('Ada Lovelace')
        ->and($user->cpf)->toBe('52998224725')
        ->and($user->email)->toBe('ada@example.test')
        ->and(Hash::check($password, $user->getRawOriginal('password')))->toBeTrue()
        ->and($affiliation->user_id)->toBe($user->id)
        ->and($affiliation->email)->toBe($user->email)
        ->and($affiliation->type)->toBe(AffiliationType::SystemAdministrator)
        ->and($affiliation->campus_id)->toBeNull()
        ->and($affiliation->course_id)->toBeNull()
        ->and($affiliation->registration_number)->toBe('ADM-001')
        ->and(EmailMessage::query()->exists())->toBeFalse()
        ->and(EmailDeliveryAttempt::query()->exists())->toBeFalse();

    $activities = Activity::query()->get()->toJson();
    expect($activities)->not->toContain($password, $user->getRawOriginal('password'));
    expect(Activity::forSubject($user)->where('event', 'created')->sole()->properties->get('actor'))
        ->toBe('system')
        ->and(Activity::forSubject($affiliation)->where('event', 'created')->sole()->properties->get('actor'))
        ->toBe('system');
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
});

test('adds only the administrator affiliation when the cpf belongs to an existing user', function () {
    Mail::fake();
    Notification::fake();
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
        ->and(Activity::forSubject($affiliation)->where('event', 'created')->sole()->properties->get('actor'))->toBe('system');
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
});

test('allows normal accounts and inactive system administrators to exist before bootstrap', function () {
    fakePasswordBreachCheck();
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

test('refuses to prompt when an active system administrator already exists', function () {
    $user = User::factory()->create();
    Affiliation::factory()->global()->for($user)->create();

    $this->artisan('admin:create')->assertFailed();

    expect(User::query()->count())->toBe(1)
        ->and(Affiliation::query()->active()->where('type', AffiliationType::SystemAdministrator->value)->count())
        ->toBe(1);
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

test('rejects an invalid affiliation email without changing the existing user', function () {
    $user = User::factory()->create(['cpf' => '52998224725']);

    $this->artisan('admin:create')
        ->expectsQuestion('CPF (11 dígitos, somente números)', '52998224725')
        ->expectsQuestion('E-mail do vínculo', 'not-an-email')
        ->assertFailed();

    expect(User::query()->count())->toBe(1)
        ->and($user->affiliations()->count())->toBe(0);
});

test('validates the initial password before asking for its confirmation', function () {
    $this->artisan('admin:create')
        ->expectsQuestion('CPF (11 dígitos, somente números)', '52998224725')
        ->expectsQuestion('Nome completo', 'Ada Lovelace')
        ->expectsQuestion('E-mail da conta', 'ada@example.test')
        ->expectsQuestion('Número de registro institucional', 'ADM-001')
        ->expectsOutputToContain('Regras da senha')
        ->expectsQuestion('Senha inicial', 'weak')
        ->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(Affiliation::query()->count())->toBe(0);
});

test('rejects a mismatched password confirmation before writing records', function () {
    fakePasswordBreachCheck();

    $this->artisan('admin:create')
        ->expectsQuestion('CPF (11 dígitos, somente números)', '52998224725')
        ->expectsQuestion('Nome completo', 'Ada Lovelace')
        ->expectsQuestion('E-mail da conta', 'ada@example.test')
        ->expectsQuestion('Número de registro institucional', 'ADM-001')
        ->expectsQuestion('Senha inicial', 'SgeInitialPassword9!a')
        ->expectsQuestion('Confirme a senha', 'SgeDifferentPassword8!b')
        ->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(Affiliation::query()->count())->toBe(0);
});

test('does not create records when the operator declines the final confirmation', function () {
    fakePasswordBreachCheck();

    expectNewAdminPrompts($this->artisan('admin:create'), confirm: false)->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(Affiliation::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});

test('does not add a link when the operator cancels an existing account', function () {
    $user = User::factory()->create(['cpf' => '52998224725']);
    $activityCount = Activity::query()->count();

    expectExistingAdminPrompts($this->artisan('admin:create'), confirm: false)->assertFailed();

    expect(User::query()->count())->toBe(1)
        ->and($user->affiliations()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe($activityCount);
});

test('rejects non-interactive execution without writing records', function () {
    $this->artisan('admin:create', ['--no-interaction' => true])->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(Affiliation::query()->count())->toBe(0);
});

test('rolls back the account and its activity if affiliation creation fails', function () {
    fakePasswordBreachCheck();
    Event::listen('eloquent.creating: '.Affiliation::class, static function (Affiliation $affiliation): void {
        throw new RuntimeException('Falha simulada.');
    });

    expectNewAdminPrompts($this->artisan('admin:create'))->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(Affiliation::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
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
});
