<?php

use App\Actions\CreateAdministrativeAffiliation;
use App\Actions\RequestEmailDelivery;
use App\Actions\UpdateAdministrativeAffiliation;
use App\Enums\AffiliationType;
use App\Enums\EmailMessagePurpose;
use App\Jobs\SendEmailDelivery;
use App\Mail\DeliveryMail;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\EmailDeliveryAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

function userManagementAdministrator(): User
{
    $user = User::factory()->create();
    $affiliation = Affiliation::factory()->global()->for($user)->create();
    test()->actingAs($user)->withSession(['active_affiliation_id' => $affiliation->id]);

    return $user;
}

function administrativeUserPayload(array $overrides = []): array
{
    return [...[
        'cpf' => '529.982.247-25', 'consulted_cpf' => '52998224725', 'name' => 'Ada Lovelace',
        'email' => ' ADA@EXAMPLE.TEST ', 'type' => 'system_administrator', 'registration_number' => ' ADM-001 ',
    ], ...$overrides];
}

beforeEach(function () {
    Bus::fake();
    Mail::fake();
});

test('creates either administrator type with one email unknown password and affiliation audit actor', function (string $type) {
    $actor = userManagementAdministrator();
    $data = administrativeUserPayload(['type' => $type]);
    if ($type === 'campus_administrator') {
        $data['campus_id'] = Campus::factory()->create()->id;
    }
    $this->post(route('users.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
    $user = User::where('cpf', '52998224725')->sole();
    $affiliation = $user->affiliations()->sole();
    expect($user->email)->toBe('ada@example.test')->and($affiliation->email)->toBe($user->email)
        ->and($affiliation->registration_number)->toBe('ADM-001')->and($affiliation->course_id)->toBeNull()
        ->and($affiliation->type->value)->toBe($type);
    $attempt = EmailDeliveryAttempt::sole();
    expect($attempt->purpose)->toBe(EmailMessagePurpose::AccountCreated);
    (new DeliveryMail($attempt))->assertSeeInHtml($affiliation->type->label());
    $audit = Activity::forSubject($user)->where('event', 'created')->sole();
    expect($audit->properties['actor'])->toBe('affiliation')->and($audit->causer_id)->toBe($actor->affiliations()->sole()->id)
        ->and(Activity::all()->toJson())->not->toContain($user->password);
    Bus::assertDispatched(SendEmailDelivery::class, 1);
    Mail::assertNothingOutgoing();
})->with(['system_administrator', 'campus_administrator']);

test('existing cpf preserves all account fields and notifies deduplicated recipients', function (bool $sameEmail) {
    userManagementAdministrator();
    $user = User::factory()->create(['cpf' => '52998224725', 'email' => 'ada@example.test']);
    $original = $user->fresh()->getAttributes();
    $data = administrativeUserPayload(['name' => 'Nome adulterado', 'email' => $sameEmail ? 'ADA@EXAMPLE.TEST' : 'affiliation@example.test']);
    $this->post(route('users.store'), $data)->assertSessionHasNoErrors()->assertRedirect(route('users.show', $user));
    expect($user->fresh()->getAttributes())->toBe($original)
        ->and(EmailDeliveryAttempt::count())->toBe($sameEmail ? 1 : 2)
        ->and(EmailDeliveryAttempt::pluck('purpose')->unique()->sole())->toBe(EmailMessagePurpose::NewAffiliation);
    Mail::assertNothingOutgoing();
})->with([true, false]);

test('campus administrators can have multiple campi but active duplicates identify the existing affiliation', function () {
    userManagementAdministrator();
    $user = User::factory()->create();
    $first = Campus::factory()->create();
    $second = Campus::factory()->create();
    $data = ['type' => 'campus_administrator', 'campus_id' => $first->id, 'email' => 'adm@example.test', 'registration_number' => 'C-1'];
    $this->post(route('users.affiliations.store', $user), $data)->assertSessionHasNoErrors();
    $existing = $user->affiliations()->sole();
    $this->post(route('users.affiliations.store', $user), $data)->assertSessionHasErrors('type');
    expect(session('errors')->first('type'))->toContain('#'.$existing->id);
    $this->post(route('users.affiliations.store', $user), [...$data, 'campus_id' => $second->id])->assertSessionHasNoErrors();
    expect($user->affiliations()->count())->toBe(2);
});

test('administrative forms reject another persons registration in every write flow', function (string $operation, bool $inactiveOwner) {
    userManagementAdministrator();
    Affiliation::factory()->server()->create(['registration_number' => 'TAKEN', 'deactivated_at' => $inactiveOwner ? now() : null]);
    $user = User::factory()->create();
    $affiliation = Affiliation::factory()->global()->for($user)->create();
    $userCount = User::count();
    $affiliationCount = Affiliation::count();
    $original = $affiliation->fresh()->getAttributes();
    $data = ['type' => 'campus_administrator', 'campus_id' => Campus::factory()->create()->id, 'email' => 'new@example.test', 'registration_number' => ' TAKEN '];
    $activityCount = Activity::count();

    if ($operation === 'account') {
        $this->post(route('users.store'), administrativeUserPayload(['registration_number' => ' TAKEN ']))->assertSessionHasErrors('registration_number');
    } elseif ($operation === 'affiliation') {
        $this->post(route('users.affiliations.store', $user), $data)->assertSessionHasErrors('registration_number');
    } else {
        $this->put(route('users.affiliations.update', [$user, $affiliation]), ['email' => $data['email'], 'registration_number' => $data['registration_number']])->assertSessionHasErrors('registration_number');
    }

    expect(User::count())->toBe($userCount)
        ->and(Affiliation::count())->toBe($affiliationCount)
        ->and($affiliation->fresh()->getAttributes())->toBe($original)
        ->and(Activity::count())->toBe($activityCount)
        ->and(EmailDeliveryAttempt::count())->toBe(0);
    Bus::assertNotDispatched(SendEmailDelivery::class);
})->with(['account', 'affiliation', 'update'])->with([true, false]);

test('administrative editing allows a number already used by the same person', function () {
    userManagementAdministrator();
    $owner = Affiliation::factory()->server()->create(['registration_number' => 'SAME-PERSON']);
    $affiliation = Affiliation::factory()->global()->for($owner->user)->create();

    $this->put(route('users.affiliations.update', [$owner->user, $affiliation]), ['email' => $affiliation->email, 'registration_number' => ' SAME-PERSON '])
        ->assertSessionHasNoErrors();

    expect($affiliation->fresh()->registration_number)->toBe('SAME-PERSON');
});

test('actions revalidate registration ownership without relying on form requests', function (bool $update) {
    $actor = userManagementAdministrator();
    Affiliation::factory()->global()->deactivated()->create(['registration_number' => 'TAKEN']);
    $target = User::factory()->create();
    $affiliation = Affiliation::factory()->global()->for($target)->create();
    $data = ['email' => 'new@example.test', 'registration_number' => 'TAKEN'];
    $campus = Campus::factory()->create();

    expect(fn () => $update
        ? app(UpdateAdministrativeAffiliation::class)->handle($actor, $target, $affiliation, 'update', $data)
        : app(CreateAdministrativeAffiliation::class)->handle([...$data, 'type' => 'campus_administrator', 'campus_id' => $campus->id], $actor, $target))
        ->toThrow(ValidationException::class, 'Este registro institucional já pertence a outra pessoa.');
})->with([true, false]);

test('edits only email and registration including inactive affiliations without new mail', function (bool $inactive) {
    userManagementAdministrator();
    $affiliation = Affiliation::factory()->global()->create(['deactivated_at' => $inactive ? now() : null]);
    $this->put(route('users.affiliations.update', [$affiliation->user, $affiliation]), ['email' => ' NEW@EXAMPLE.TEST ', 'registration_number' => ' ADM-NEW '])->assertSessionHasNoErrors();
    expect($affiliation->fresh()->email)->toBe('new@example.test')->and($affiliation->fresh()->registration_number)->toBe('ADM-NEW')
        ->and(Activity::forSubject($affiliation)->where('event', 'updated')->sole()->attribute_changes['attributes']['email'])->toBe('new@example.test');
    expect(EmailDeliveryAttempt::count())->toBe(0);
    Bus::assertNotDispatched(SendEmailDelivery::class);
})->with([true, false]);

test('deactivation requires password and confirmation and reactivation is audited without invitation', function () {
    userManagementAdministrator();
    $affiliation = Affiliation::factory()->global()->create();
    $route = route('users.affiliations.deactivate', [$affiliation->user, $affiliation]);
    $this->patch($route, ['confirmed' => 1, 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    $this->patch($route, ['current_password' => 'password'])->assertSessionHasErrors('confirmed');
    $this->patch($route, ['confirmed' => 1, 'current_password' => 'password'])->assertSessionHasNoErrors();
    expect($affiliation->fresh()->deactivated_at)->not->toBeNull();
    $route = route('users.affiliations.reactivate', [$affiliation->user, $affiliation]);
    $this->patch($route)->assertSessionHasErrors('confirmed');
    $this->patch($route, ['confirmed' => 1])->assertSessionHasNoErrors();
    expect($affiliation->fresh()->deactivated_at)->toBeNull()->and(EmailDeliveryAttempt::count())->toBe(2)
        ->and(Activity::forSubject($affiliation)->where('event', 'updated')->count())->toBe(2);
});

test('reactivation refuses duplicate active affiliation and leaves historical duplicates intact', function () {
    userManagementAdministrator();
    $user = User::factory()->create();
    $active = Affiliation::factory()->global()->for($user)->create();
    $inactive = Affiliation::factory()->global()->deactivated()->for($user)->create();
    $this->patch(route('users.affiliations.reactivate', [$user, $inactive]), ['confirmed' => 1])->assertSessionHasErrors('type');
    expect($inactive->fresh()->deactivated_at)->not->toBeNull()->and($active->fresh()->deactivated_at)->toBeNull()
        ->and($user->affiliations()->count())->toBe(2);
});

test('selected affiliation cannot be deactivated even when another system administrator exists', function () {
    $actor = userManagementAdministrator();
    Affiliation::factory()->global()->create();
    $selected = $actor->affiliations()->sole();
    $this->patch(route('users.affiliations.deactivate', [$actor, $selected]), ['confirmed' => 1, 'current_password' => 'password'])->assertForbidden();
    expect($selected->fresh()->deactivated_at)->toBeNull();
});

test('a campus deactivated after the page was loaded rejects every write', function (string $operation) {
    userManagementAdministrator();
    $campus = Campus::factory()->create();
    $affiliation = Affiliation::factory()->for($campus)->create(['type' => AffiliationType::CampusAdministrator]);
    if ($operation === 'reactivate') {
        $affiliation->update(['deactivated_at' => now()]);
    }
    $campus->deactivate();
    $route = route('users.affiliations.'.$operation, [$affiliation->user, $affiliation]);
    $data = $operation === 'update' ? ['email' => 'new@example.test', 'registration_number' => 'NEW'] : ['confirmed' => 1];
    if ($operation === 'deactivate') {
        $data['current_password'] = 'password';
    }
    $this->{$operation === 'update' ? 'put' : 'patch'}($route, $data)->assertForbidden();
})->with(['update', 'deactivate', 'reactivate']);

test('nested writes reject affiliation belonging to a different user', function () {
    userManagementAdministrator();
    $user = User::factory()->create();
    $affiliation = Affiliation::factory()->global()->create();
    $this->put(route('users.affiliations.update', [$user, $affiliation]), ['email' => 'new@example.test', 'registration_number' => 'NEW'])->assertNotFound();
    $this->get(route('users.affiliations.edit', [$user, $affiliation]))->assertNotFound();
});

test('creation rejects invalid or tampered fields', function (array $overrides, string $field) {
    userManagementAdministrator();
    $this->post(route('users.store'), administrativeUserPayload($overrides))->assertSessionHasErrors($field);
    expect(User::where('cpf', '52998224725')->exists())->toBeFalse();
})->with([
    [['cpf' => '12345678901'], 'cpf'], [['consulted_cpf' => '11111111111'], 'consulted_cpf'],
    [['type' => 'student'], 'type'], [['type' => ''], 'type'], [['type' => 'campus_administrator'], 'campus_id'],
    [['campus_id' => 1], 'campus_id'], [['course_id' => 1], 'course_id'], [['password' => 'injected'], 'password'], [['user_id' => 1], 'user_id'], [['login_email' => 'other@example.test'], 'login_email'],
]);

test('edit rejects identity type campus and unexpected fields', function (string $field) {
    userManagementAdministrator();
    $affiliation = Affiliation::factory()->global()->create();
    $this->put(route('users.affiliations.update', [$affiliation->user, $affiliation]), ['email' => 'new@example.test', 'registration_number' => 'NEW', $field => '1'])->assertSessionHasErrors($field);
    expect($affiliation->fresh()->email)->toBe($affiliation->email);
})->with(['user_id', 'name', 'cpf', 'type', 'campus_id', 'course_id', 'deactivated_at', 'password']);

test('new login email cannot belong to another account but existing account affiliation email may', function () {
    userManagementAdministrator();
    User::factory()->create(['email' => 'ada@example.test']);
    $this->post(route('users.store'), administrativeUserPayload())->assertSessionHasErrors('email');
    expect(User::where('cpf', '52998224725')->exists())->toBeFalse();
});

test('creation revalidates campus activity in the transaction', function () {
    $actor = userManagementAdministrator();
    $campus = Campus::factory()->deactivated()->create();
    expect(fn () => app(CreateAdministrativeAffiliation::class)->handle(administrativeUserPayload(['type' => 'campus_administrator', 'campus_id' => $campus->id]), $actor))
        ->toThrow(ValidationException::class);
});

test('incorrect selected profiles have no administrative user access even with another administrator affiliation', function (string $type) {
    $user = userManagementAdministrator();
    $selected = Affiliation::factory()->for($user)->create(['type' => AffiliationType::from($type)]);
    $this->withSession(['active_affiliation_id' => $selected->id]);
    $this->get(route('users.index'))->assertForbidden();
    $this->get(route('users.create'))->assertForbidden();
    $this->post(route('users.store'), administrativeUserPayload())->assertForbidden();
})->with(['campus_administrator', 'internship_office', 'advisor', 'teaching_direction']);

test('transaction reauthorizes when context is switched or deactivated after waiting for the administrative lock', function (bool $deactivate) {
    $actor = userManagementAdministrator();
    $selected = $actor->affiliations()->sole();
    $other = Affiliation::factory()->for($actor)->create(['type' => AffiliationType::CampusAdministrator]);
    $triggered = false;
    DB::listen(function ($query) use (&$triggered, $selected, $other, $deactivate): void {
        if (! $triggered && str_contains($query->sql, 'for update')) {
            $triggered = true;
            if ($deactivate) {
                $selected->update(['deactivated_at' => now()]);
            } else {
                session()->put('active_affiliation_id', $other->id);
            }
        }
    });
    expect(fn () => app(CreateAdministrativeAffiliation::class)->handle(administrativeUserPayload(), $actor))
        ->toThrow(HttpException::class);
    expect(User::where('cpf', '52998224725')->exists())->toBeFalse();
})->with([true, false]);

test('reservation failure rolls back account affiliation audit and sends no email', function () {
    $actor = userManagementAdministrator();
    $count = Activity::count();
    $this->mock(RequestEmailDelivery::class)->shouldReceive('accountCreated')->once()->andThrow(new RuntimeException('reservation failed'));
    expect(fn () => app(CreateAdministrativeAffiliation::class)->handle(administrativeUserPayload(), $actor))->toThrow(RuntimeException::class);
    expect(User::where('cpf', '52998224725')->exists())->toBeFalse()->and(Activity::count())->toBe($count)->and(EmailDeliveryAttempt::count())->toBe(0);
    Bus::assertNotDispatched(SendEmailDelivery::class);
    Mail::assertNothingOutgoing();
});
