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
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Spatie\Activitylog\Models\Activity;

function queueAdminCreatePrompts(array $values, bool $confirm = true): void
{
    $keys = [];

    foreach ($values as $value) {
        $keys = [...$keys, ...mb_str_split($value), Key::ENTER];
    }

    array_push($keys, $confirm ? 'y' : 'n', Key::ENTER);

    Prompt::fake($keys);
}

function fakePasswordBreachCheck(): void
{
    Http::fake([
        'https://api.pwnedpasswords.com/range/*' => Http::response(''),
    ]);
    Http::preventStrayRequests();
}

test('creates the initial system administrator with a shared email and a hashed password', function () {
    fakePasswordBreachCheck();
    Mail::fake();
    Notification::fake();
    $password = 'SgeInitialPassword9!a';
    queueAdminCreatePrompts([
        'Ada Lovelace',
        '52998224725',
        'ADA@EXAMPLE.TEST',
        'ADM-001',
        $password,
        $password,
    ]);

    $this->artisan('admin:create')->assertSuccessful();

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
    Prompt::assertOutputDoesntContain($password);
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
});

test('allows normal accounts and inactive system administrators to exist before bootstrap', function () {
    fakePasswordBreachCheck();
    User::factory()->create();
    $inactiveAdministrator = User::factory()->create();
    Affiliation::factory()->global()->deactivated()->for($inactiveAdministrator)->create();
    queueAdminCreatePrompts([
        'Grace Hopper',
        '11144477735',
        'grace@example.test',
        'ADM-002',
        'SgeInitialPassword9!a',
        'SgeInitialPassword9!a',
    ]);

    $this->artisan('admin:create')->assertSuccessful();

    expect(Affiliation::query()->active()->where('type', AffiliationType::SystemAdministrator->value)->count())
        ->toBe(1)
        ->and(User::query()->count())->toBe(3);
});

test('refuses to prompt when an active system administrator already exists', function () {
    $user = User::factory()->create();
    Affiliation::factory()->global()->for($user)->create();

    $this->artisan('admin:create')->assertFailed();

    expect(User::query()->count())->toBe(1)
        ->and(Affiliation::query()->active()->where('type', AffiliationType::SystemAdministrator->value)->count())
        ->toBe(1);
});

test('re-prompts until the cpf contains eleven digits and the email is unique', function () {
    fakePasswordBreachCheck();
    User::factory()->create([
        'cpf' => '93541134780',
        'email' => 'taken@example.test',
    ]);
    queueAdminCreatePrompts([
        'Katherine Johnson',
        '529.982.247-25',
        '93541134780',
        '11144477735',
        'TAKEN@EXAMPLE.TEST',
        'katherine@example.test',
        'ADM-003',
        'SgeInitialPassword9!a',
        'SgeInitialPassword9!a',
    ]);

    $this->artisan('admin:create')->assertSuccessful();

    $user = User::query()->where('email', 'katherine@example.test')->sole();
    expect($user->cpf)->toBe('11144477735')
        ->and(Affiliation::query()->whereBelongsTo($user)->sole()->email)->toBe($user->email);
});

test('shows password rules and validates the password before requesting its confirmation', function () {
    fakePasswordBreachCheck();
    queueAdminCreatePrompts([
        'Katherine Johnson',
        '52998224725',
        'katherine@example.test',
        'ADM-004',
        'weak',
        'SgeInitialPassword9!a',
        'SgeDifferentPassword8!b',
        'SgeInitialPassword9!a',
    ]);

    $this->artisan('admin:create')->assertSuccessful();

    $output = Prompt::strippedContent();
    $rulesPosition = strpos($output, 'Regras da senha');
    $passwordPosition = strpos($output, 'Senha inicial');

    expect($rulesPosition)->toBeInt()
        ->and($passwordPosition)->toBeInt()
        ->and($rulesPosition)->toBeLessThan($passwordPosition);
    expect(User::query()->count())->toBe(1)
        ->and(Affiliation::query()->count())->toBe(1);
});

test('does not create records when the operator declines the final confirmation', function () {
    fakePasswordBreachCheck();
    queueAdminCreatePrompts([
        'Katherine Johnson',
        '52998224725',
        'katherine@example.test',
        'ADM-005',
        'SgeInitialPassword9!a',
        'SgeInitialPassword9!a',
    ], confirm: false);

    $this->artisan('admin:create')->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(Affiliation::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
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
    queueAdminCreatePrompts([
        'Katherine Johnson',
        '52998224725',
        'katherine@example.test',
        'ADM-006',
        'SgeInitialPassword9!a',
        'SgeInitialPassword9!a',
    ]);

    $this->artisan('admin:create')->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(Affiliation::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});
