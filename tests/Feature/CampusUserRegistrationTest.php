<?php

use App\Actions\CreateCampusAffiliation;
use App\Actions\RequestEmailDelivery;
use App\Enums\AffiliationType;
use App\Enums\EmailMessagePurpose;
use App\Jobs\SendEmailDelivery;
use App\Mail\DeliveryMail;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\EmailDeliveryAttempt;
use App\Models\User;
use App\Models\UserPersonalData;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

function campusUserPayload(array $overrides = []): array
{
    return [...[
        'cpf' => '529.982.247-25', 'consulted_cpf' => '52998224725', 'name' => 'Ada Lovelace',
        'email' => ' ADA@EXAMPLE.TEST ', 'type' => 'advisor', 'registration_number' => ' MAT-001 ',
    ], ...$overrides];
}

beforeEach(function () {
    Bus::fake();
    $this->office = Affiliation::factory()->server()->create();
    $this->actor = $this->office->user;
    $this->campus = $this->office->campus;
    $this->actingAs($this->actor)->withSession(['active_affiliation_id' => $this->office->id]);
});

test('office registers every non administrator type on its selected campus', function (AffiliationType $type) {
    $payload = campusUserPayload(['type' => $type->value]);
    if ($type === AffiliationType::Supervisor) {
        unset($payload['registration_number']);
    }
    if ($type === AffiliationType::Student) {
        $payload['course_id'] = Course::factory()->for($this->campus)->create()->id;
    }
    $this->post(route('users.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
    $user = User::where('cpf', '52998224725')->sole();
    $affiliation = $user->affiliations()->sole();
    expect($user->email)->toBe('ada@example.test')->and($user->name)->toBe('Ada Lovelace')
        ->and($affiliation->email)->toBe($user->email)->and($affiliation->campus_id)->toBe($this->campus->id)
        ->and($affiliation->type)->toBe($type)
        ->and($affiliation->registration_number)->toBe($type === AffiliationType::Supervisor ? null : 'MAT-001')
        ->and($affiliation->course_id)->toBe($payload['course_id'] ?? null)
        ->and($user->personalData)->toBeNull();
    expect(EmailDeliveryAttempt::sole()->purpose)->toBe(EmailMessagePurpose::AccountCreated);
    Bus::assertDispatched(SendEmailDelivery::class, 1);
    $audit = Activity::forSubject($affiliation)->where('event', 'created')->sole();
    expect($audit->causer_id)->toBe($this->office->id)->and(Activity::all()->toJson())->not->toContain($user->password);
})->with(array_values(array_filter(AffiliationType::cases(), fn (AffiliationType $type): bool => $type !== AffiliationType::SystemAdministrator)));

test('office rejects administrator type campus injection and unrecognized fields without writing', function (array $overrides, string $field) {
    $users = User::count();
    $affiliations = Affiliation::count();
    $activities = Activity::count();
    $this->post(route('users.store'), campusUserPayload($overrides))->assertSessionHasErrors($field);
    expect(User::count())->toBe($users)->and(Affiliation::count())->toBe($affiliations)->and(Activity::count())->toBe($activities)
        ->and(EmailDeliveryAttempt::count())->toBe(0);
})->with([
    'administrator' => [['type' => 'system_administrator'], 'type'],
    'legacy administrator' => [['type' => 'campus_administrator'], 'type'],
    'another campus' => [fn () => ['campus_id' => Campus::factory()->create()->id], 'campus_id'],
    'missing lookup' => [['consulted_cpf' => null], 'consulted_cpf'],
    'changed cpf' => [['consulted_cpf' => '11144477735'], 'consulted_cpf'],
    'invalid cpf' => [['cpf' => '11111111111', 'consulted_cpf' => '11111111111'], 'cpf'],
    'missing registration' => [['registration_number' => null], 'registration_number'],
    'supervisor registration' => [['type' => 'supervisor'], 'registration_number'],
    'non student course' => [fn () => ['course_id' => Course::factory()->for($this->campus)->create()->id], 'course_id'],
    'non supervisor profession' => [['job_role' => 'Engenheira'], 'job_role'],
    'privileged field' => [['deactivated_at' => '2026-10-01'], 'deactivated_at'],
]);

test('students require an active course from the selected campus', function (string $condition) {
    $course = match ($condition) {
        'foreign' => Course::factory()->create(),
        'inactive' => Course::factory()->for($this->campus)->create(['deactivated_at' => now()]),
        default => null,
    };
    $this->post(route('users.store'), campusUserPayload(['type' => 'student', 'course_id' => $course?->id]))->assertSessionHasErrors('course_id');
    expect(User::where('cpf', '52998224725')->exists())->toBeFalse();
})->with(['missing', 'foreign', 'inactive']);

test('supervisor professional data is optional validated and stored in the shared profile', function () {
    $this->post(route('users.store'), campusUserPayload([
        'type' => 'supervisor', 'registration_number' => null, 'job_role' => 'Engenheira',
        'qualification' => 'Mestre', 'training' => 'Engenharia', 'professional_experience' => 'Dez anos',
    ]))->assertSessionHasNoErrors();
    $user = User::where('cpf', '52998224725')->sole();
    expect($user->affiliations()->sole()->registration_number)->toBeNull()
        ->and($user->personalData->job_role)->toBe('Engenheira')->and($user->personalData->qualification)->toBe('Mestre')
        ->and($user->personalData->training)->toBe('Engenharia')->and($user->personalData->professional_experience)->toBe('Dez anos');
    expect(Activity::forSubject($user->personalData)->where('event', 'created')->sole()->causer_id)->toBe($this->office->id);
});

test('invalid supervisor optional data rejects the whole registration', function () {
    $this->post(route('users.store'), campusUserPayload(['type' => 'supervisor', 'registration_number' => null, 'job_role' => str_repeat('a', 256)]))
        ->assertSessionHasErrors('job_role');
    expect(User::where('cpf', '52998224725')->exists())->toBeFalse()->and(EmailDeliveryAttempt::count())->toBe(0);
});

test('existing account and omitted professional data are preserved and affiliation recipients are deduplicated', function (bool $sameEmail) {
    $user = User::factory()->create(['cpf' => '52998224725', 'email' => 'ada@example.test']);
    $profile = UserPersonalData::factory()->for($user)->create(['job_role' => 'Cargo anterior', 'training' => 'Formação anterior']);
    $original = $user->fresh()->getAttributes();
    $originalProfile = $profile->fresh()->getAttributes();
    $this->post(route('users.store'), campusUserPayload([
        'type' => 'supervisor', 'registration_number' => null, 'name' => 'Nome adulterado',
        'email' => $sameEmail ? 'ADA@EXAMPLE.TEST' : 'context@example.test', 'job_role' => '',
    ]))->assertSessionHasNoErrors();
    expect($user->fresh()->getAttributes())->toBe($original)->and($profile->fresh()->getAttributes())->toBe($originalProfile)
        ->and(EmailDeliveryAttempt::count())->toBe($sameEmail ? 1 : 2)
        ->and(EmailDeliveryAttempt::pluck('purpose')->unique()->sole())->toBe(EmailMessagePurpose::NewAffiliation);
    (new DeliveryMail(EmailDeliveryAttempt::first()))->assertDontSeeInHtml('Matrícula:');
})->with([true, false]);

test('existing cpf can receive a new campus affiliation but active duplicates are rejected', function () {
    $user = User::factory()->create(['cpf' => '52998224725']);
    Affiliation::factory()->for($user)->create(['type' => AffiliationType::Advisor, 'registration_number' => 'MAT-001']);
    $this->post(route('users.store'), campusUserPayload())->assertSessionHasNoErrors();
    $this->post(route('users.store'), campusUserPayload())->assertSessionHasErrors('type');
    expect($user->affiliations()->count())->toBe(2);
});

test('registrations preserve ownership including inactive history and student uniqueness', function (string $condition) {
    $user = User::factory()->create($condition === 'same student' ? ['cpf' => '52998224725'] : []);
    $affiliation = $condition === 'same student'
        ? Affiliation::factory()->student()->for($user)->create(['registration_number' => 'MAT-001'])
        : Affiliation::factory()->deactivated()->for($user)->create(['registration_number' => 'MAT-001']);
    $payload = campusUserPayload();
    if ($condition === 'same student') {
        $payload['type'] = 'student';
        $payload['course_id'] = Course::factory()->for($this->campus)->create()->id;
    }
    $this->post(route('users.store'), $payload)->assertSessionHasErrors('registration_number');
    expect($affiliation->fresh()->registration_number)->toBe('MAT-001');
})->with(['inactive owner', 'same student']);

test('office cannot create administrator affiliations or target accounts outside its campus', function () {
    $target = User::factory()->create();
    $this->get(route('users.index'))->assertOk();
    $this->get(route('users.create'))->assertOk();
    $this->post(route('users.store'), campusUserPayload(['type' => 'system_administrator']))->assertSessionHasErrors('type');
    $this->post(route('users.affiliations.store', $target), ['type' => 'system_administrator', 'registration_number' => 'ADM', 'email' => 'admin@example.test'])->assertForbidden();
    $this->get(route('users.edit', $target))->assertForbidden();
});

test('other selected roles do not receive campus registration even with an office affiliation', function (AffiliationType $type) {
    $other = Affiliation::factory()->for($this->actor)->create([
        'type' => $type, 'campus_id' => $type === AffiliationType::SystemAdministrator ? null : $this->campus->id,
        'registration_number' => $type === AffiliationType::Supervisor ? null : $this->office->registration_number,
        'course_id' => $type === AffiliationType::Student ? Course::factory()->for($this->campus)->create()->id : null,
    ]);
    $this->withSession(['active_affiliation_id' => $other->id]);
    $this->get(route('users.create'))->assertForbidden();
    $this->post(route('users.store'), campusUserPayload())->assertForbidden();
})->with(array_values(array_filter(AffiliationType::cases(), fn (AffiliationType $type): bool => ! in_array($type, [AffiliationType::InternshipOffice, AffiliationType::SystemAdministrator], true))));

test('inactive selected affiliation or campus blocks both page and registration', function (string $condition) {
    if ($condition === 'affiliation') {
        $this->office->update(['deactivated_at' => now()]);
        $this->get(route('users.create'))->assertForbidden();
        $this->post(route('users.store'), campusUserPayload())->assertForbidden();
    } else {
        $this->campus->deactivate();
        $this->get(route('users.create'))->assertForbidden();
        $this->post(route('users.store'), campusUserPayload())->assertForbidden();
    }
    expect(User::where('cpf', '52998224725')->exists())->toBeFalse();
})->with(['affiliation', 'campus']);

test('registration action independently rejects privileged types and rechecks a switched context during writing', function (string $condition) {
    if ($condition === 'type') {
        expect(fn () => app(CreateCampusAffiliation::class)->handle($this->actor, campusUserPayload(['type' => 'system_administrator'])))
            ->toThrow(ValidationException::class);
    } else {
        $other = Affiliation::factory()->for($this->actor)->create();
        $triggered = false;
        DB::listen(function ($query) use ($other, &$triggered): void {
            if (! $triggered && str_contains($query->sql, '"users"') && str_contains($query->sql, 'for update')) {
                $triggered = true;
                session()->put('active_affiliation_id', $other->id);
            }
        });
        $this->post(route('users.store'), campusUserPayload())->assertForbidden();
    }
    expect(User::where('cpf', '52998224725')->exists())->toBeFalse()->and(EmailDeliveryAttempt::count())->toBe(0);
})->with(['type', 'context']);

test('campus registration reuses the cpf flow and scopes type course and registration choices', function () {
    $this->get(route('dashboard'))->assertOk()->assertSee(route('users.index'), false);
    $this->get(route('users.create'))->assertOk();
    $local = Course::factory()->for($this->campus)->create();
    $foreign = Course::factory()->create();
    $inactive = Course::factory()->for($this->campus)->create(['deactivated_at' => now()]);
    $component = Livewire::test('users.form-fields')->set('cpf', '52998224725')->call('lookupCpf');
    $component->assertDontSee('value="system_administrator"', false)->assertDontSee('name="campus_id"', false)
        ->set('type', 'student')->assertSee('Matrícula')->assertSee($local->name)->assertDontSee($foreign->name)->assertDontSee($inactive->name)
        ->set('registrationNumber', 'STALE')->set('type', 'supervisor')->assertSet('registrationNumber', '')
        ->assertDontSee('name="registration_number"', false)->assertDontSee('name="course_id"', false)
        ->assertSee('name="job_role"', false)->assertSee('name="professional_experience"', false);
});

test('cpf lookup and copying a registration do not expose other campus affiliations', function () {
    $user = User::factory()->create(['cpf' => '52998224725']);
    $local = Affiliation::factory()->for($user)->for($this->campus)->create(['registration_number' => 'LOCAL']);
    $foreign = Affiliation::factory()->for($user)->create(['registration_number' => 'FOREIGN']);
    Livewire::test('users.form-fields')->set('cpf', '52998224725')->call('lookupCpf')->assertSee($user->name)
        ->assertSee('LOCAL')->assertDontSee('FOREIGN')->set('registrationSourceId', (string) $foreign->id)->assertHasErrors('registrationSourceId')
        ->set('registrationSourceId', (string) $local->id)->assertSet('registrationNumber', 'LOCAL');
});

test('validation errors restore campus registration fields', function () {
    $this->from(route('users.create'))->post(route('users.store'), campusUserPayload([
        'type' => 'supervisor', 'registration_number' => null, 'email' => 'invalid', 'job_role' => 'Engenheira', 'training' => 'Formação',
    ]))->assertSessionHasErrors('email');
    $this->get(route('users.create'))->assertOk()->assertSee('Engenheira')->assertSee('Formação')->assertSee('invalid')
        ->assertDontSee('name="registration_number"', false);
});

test('mounted campus pages and fields reauthorize refreshes', function (string $component) {
    $test = Livewire::test($component);
    $this->campus->deactivate();
    $test->call('$refresh')->assertForbidden();
})->with(['pages::users.create', 'users.form-fields']);

test('field component locks its selected affiliation and rejects switching campus', function () {
    expect(fn () => Livewire::test('users.form-fields')->set('activeAffiliationId', 1))->toThrow(CannotUpdateLockedPropertyException::class);
    $test = Livewire::test('users.form-fields');
    $other = Affiliation::factory()->for($this->actor)->create();
    session()->put('active_affiliation_id', $other->id);
    $test->call('lookupCpf')->assertForbidden();
});

test('new login email is unique and existing accounts can reuse their own registration', function () {
    $user = User::factory()->create(['email' => 'ada@example.test']);
    $this->post(route('users.store'), campusUserPayload())->assertSessionHasErrors('email');
    expect(User::where('cpf', '52998224725')->exists())->toBeFalse();
    $user->update(['cpf' => '52998224725']);
    Affiliation::factory()->for($user)->for($this->campus)->create(['type' => AffiliationType::Coordinator, 'registration_number' => 'MAT-001']);
    $this->post(route('users.store'), campusUserPayload())->assertSessionHasNoErrors();
    expect($user->affiliations()->where('registration_number', 'MAT-001')->count())->toBe(2);
});

test('actor campus and course eligibility are rechecked after locks', function (string $condition) {
    $course = Course::factory()->for($this->campus)->create();
    $triggered = false;
    DB::listen(function ($query) use ($condition, $course, &$triggered): void {
        if (! $triggered && str_contains($query->sql, 'for update') && str_contains($query->sql, '"users"')) {
            $triggered = true;
            match ($condition) {
                'affiliation' => $this->office->update(['deactivated_at' => now()]),
                'campus' => $this->campus->deactivate(),
                'course' => $course->update(['deactivated_at' => now()]),
            };
        }
    });
    $response = $this->post(route('users.store'), campusUserPayload(['type' => 'student', 'course_id' => $course->id]));
    if ($condition === 'course') {
        $response->assertSessionHasErrors('course_id');
    } else {
        $response->assertForbidden();
    }
    expect(User::where('cpf', '52998224725')->exists())->toBeFalse()->and(EmailDeliveryAttempt::count())->toBe(0);
})->with(['affiliation', 'campus', 'course']);

test('invitation failure rolls back account supervisor profile affiliation and audit', function () {
    $activities = Activity::count();
    $profiles = UserPersonalData::count();
    $affiliations = Affiliation::count();
    $this->mock(RequestEmailDelivery::class)->shouldReceive('accountCreated')->once()->andThrow(new RuntimeException('reservation failed'));
    expect(fn () => app(CreateCampusAffiliation::class)->handle($this->actor, campusUserPayload([
        'type' => 'supervisor', 'registration_number' => null, 'job_role' => 'Engenheira',
    ])))->toThrow(RuntimeException::class);
    expect(User::where('cpf', '52998224725')->exists())->toBeFalse()->and(Activity::count())->toBe($activities)
        ->and(UserPersonalData::count())->toBe($profiles)->and(Affiliation::count())->toBe($affiliations)->and(EmailDeliveryAttempt::count())->toBe(0);
    Bus::assertNotDispatched(SendEmailDelivery::class);
});

test('rejected administrator selection is not restored into campus type options', function () {
    $this->from(route('users.create'))->post(route('users.store'), campusUserPayload(['type' => 'system_administrator']))->assertSessionHasErrors('type');
    $this->get(route('users.create'))->assertOk()->assertDontSee('value="system_administrator"', false);
});
