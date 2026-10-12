<?php

use App\Actions\UpdateAdministrativeAffiliation;
use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Course;
use App\Models\EmailDeliveryAttempt;
use App\Models\User;
use App\Models\UserPersonalData;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Bus::fake();
    $this->office = Affiliation::factory()->server()->create();
    $this->actingAs($this->office->user)->withSession(['active_affiliation_id' => $this->office->id]);
    $this->target = User::factory()->create();
});

test('office edits another persons local affiliation of each permitted type including inactive history', function (AffiliationType $type, bool $inactive) {
    $affiliation = Affiliation::factory()->for($this->target)->for($this->office->campus)->create([
        'type' => $type, 'registration_number' => $type === AffiliationType::Supervisor ? null : 'ORIGINAL',
        'course_id' => $type === AffiliationType::Student ? Course::factory()->for($this->office->campus)->create()->id : null,
        'deactivated_at' => $inactive ? now() : null,
    ]);
    $originalAccount = $this->target->fresh()->getAttributes();
    $this->get(route('users.show', $this->target))->assertOk();
    $this->get(route('users.affiliations.edit', [$this->target, $affiliation]))->assertOk();
    $payload = ['email' => ' UPDATED@EXAMPLE.TEST '];
    if ($type !== AffiliationType::Supervisor) {
        $payload['registration_number'] = ' CORRECTED ';
    } else {
        $this->get(route('users.affiliations.edit', [$this->target, $affiliation]));
    }
    $this->put(route('users.affiliations.update', [$this->target, $affiliation]), $payload)->assertSessionHasNoErrors()->assertRedirect(route('users.show', $this->target));
    expect($affiliation->fresh()->email)->toBe('updated@example.test')
        ->and($affiliation->fresh()->registration_number)->toBe($type === AffiliationType::Supervisor ? null : 'CORRECTED')
        ->and($affiliation->fresh()->type)->toBe($type)->and($affiliation->fresh()->campus_id)->toBe($affiliation->campus_id)
        ->and($affiliation->fresh()->course_id)->toBe($affiliation->course_id)
        ->and($affiliation->fresh()->deactivated_at?->toDateTimeString())->toBe($affiliation->deactivated_at?->toDateTimeString())
        ->and($this->target->fresh()->getAttributes())->toBe($originalAccount)
        ->and(Activity::forSubject($affiliation)->where('event', 'updated')->sole()->causer_id)->toBe($this->office->id)
        ->and(EmailDeliveryAttempt::count())->toBe(0);
})->with(array_values(array_filter(AffiliationType::cases(), fn (AffiliationType $type): bool => $type !== AffiliationType::SystemAdministrator)))
    ->with([false, true]);

test('supervisor edit loads and updates optional shared professional data without requiring a registration', function () {
    $affiliation = Affiliation::factory()->supervisor()->for($this->target)->for($this->office->campus)->create();
    $profile = UserPersonalData::factory()->for($this->target)->create(['job_role' => 'Cargo anterior', 'training' => 'Formação anterior']);
    $this->get(route('users.affiliations.edit', [$this->target, $affiliation]))->assertOk();
    $this->put(route('users.affiliations.update', [$this->target, $affiliation]), [
        'email' => 'context@example.test', 'job_role' => 'Engenheira', 'qualification' => 'Mestre',
    ])->assertSessionHasNoErrors();
    expect($profile->fresh()->job_role)->toBe('Engenheira')->and($profile->fresh()->qualification)->toBe('Mestre')
        ->and($profile->fresh()->training)->toBe('Formação anterior')->and($affiliation->fresh()->registration_number)->toBeNull()
        ->and(Activity::forSubject($profile)->where('event', 'updated')->sole()->causer_id)->toBe($this->office->id);
    $this->put(route('users.affiliations.update', [$this->target, $affiliation]), ['email' => 'context@example.test', 'job_role' => null])->assertSessionHasNoErrors();
    expect($profile->fresh()->job_role)->toBeNull()->and($profile->fresh()->training)->toBe('Formação anterior');
});

test('supervisor editing rejects registration and invalid professional data atomically', function (array $extra, string $field) {
    $affiliation = Affiliation::factory()->supervisor()->for($this->target)->for($this->office->campus)->create();
    $original = $affiliation->fresh()->getAttributes();
    $this->put(route('users.affiliations.update', [$this->target, $affiliation]), ['email' => 'new@example.test', ...$extra])->assertSessionHasErrors($field);
    expect($affiliation->fresh()->getAttributes())->toBe($original)->and($this->target->personalData)->toBeNull();
})->with([
    'registration' => [['registration_number' => 'FORGED'], 'registration_number'],
    'job role' => [['job_role' => str_repeat('a', 256)], 'job_role'],
    'qualification' => [['qualification' => str_repeat('a', 256)], 'qualification'],
]);

test('editing student registration preserves uniqueness and its historical course association', function () {
    $course = Course::factory()->for($this->office->campus)->create();
    $student = Affiliation::factory()->student()->for($this->target)->for($this->office->campus)->create(['course_id' => $course->id]);
    $course->update(['deactivated_at' => now()]);
    $otherStudent = Affiliation::factory()->student()->for($this->target)->create();
    $url = route('users.affiliations.update', [$this->target, $student]);
    $this->put($url, ['email' => 'new@example.test', 'registration_number' => $student->registration_number])->assertSessionHasNoErrors();
    $this->put($url, ['email' => 'new@example.test', 'registration_number' => $otherStudent->registration_number])->assertSessionHasErrors('registration_number');
    expect($student->fresh()->course_id)->toBe($course->id)->and($student->fresh()->registration_number)->toBe($student->registration_number);
});

test('campus editing rejects forged immutable fields and professional data for non supervisors', function (string $field) {
    $affiliation = Affiliation::factory()->server()->for($this->target)->for($this->office->campus)->create();
    $this->put(route('users.affiliations.update', [$this->target, $affiliation]), [
        'email' => 'new@example.test', 'registration_number' => 'NEW', $field => 'forged',
    ])->assertSessionHasErrors($field);
    expect($affiliation->fresh()->email)->toBe($affiliation->email);
})->with(['type', 'campus_id', 'course_id', 'user_id', 'deactivated_at', 'job_role']);

test('office cannot edit global administrator or foreign affiliations through direct actions or pages', function (bool $global) {
    $target = $global ? Affiliation::factory()->global()->for($this->target)->create() : Affiliation::factory()->server()->for($this->target)->create();
    $data = ['email' => 'new@example.test', 'registration_number' => 'NEW'];
    $this->put(route('users.affiliations.update', [$this->target, $target]), $data)->assertForbidden();
    expect(fn () => app(UpdateAdministrativeAffiliation::class)->handle($this->office->user, $this->target, $target, 'update', $data))->toThrow(AuthorizationException::class);
    $this->get(route('users.affiliations.edit', [$this->target, $target]))->assertForbidden();
})->with([true, false]);

test('open campus editing forms revoke access after changing the active campus', function () {
    $affiliation = Affiliation::factory()->server()->for($this->target)->for($this->office->campus)->create();
    $component = Livewire::test('pages::users.affiliations.edit', ['user' => $this->target, 'affiliation' => $affiliation]);
    $other = Affiliation::factory()->server()->for($this->office->user)->create();
    session()->put('active_affiliation_id', $other->id);
    $component->call('$refresh')->assertForbidden();
});

test('campus affiliation editing rechecks authorization after target locks', function () {
    $affiliation = Affiliation::factory()->server()->for($this->target)->for($this->office->campus)->create();
    $triggered = false;
    DB::listen(function ($query) use (&$triggered): void {
        if (! $triggered && str_contains($query->sql, '"users"') && str_contains($query->sql, 'for update')) {
            $triggered = true;
            $this->office->campus->deactivate();
        }
    });
    $this->put(route('users.affiliations.update', [$this->target, $affiliation]), ['email' => 'new@example.test', 'registration_number' => 'NEW'])->assertForbidden();
    expect($affiliation->fresh()->email)->toBe($affiliation->email);
});
