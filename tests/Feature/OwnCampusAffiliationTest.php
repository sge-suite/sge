<?php

use App\Actions\UpdateAdministrativeAffiliation;
use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Course;
use App\Models\EmailDeliveryAttempt;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Bus::fake();
    $this->office = Affiliation::factory()->server()->create(['email' => 'office@example.test', 'registration_number' => 'OFFICE-001']);
    $this->actor = $this->office->user;
    $this->actingAs($this->actor)->withSession(['active_affiliation_id' => $this->office->id]);
});

test('office sees and uses the existing form to edit its own selected affiliation', function () {
    $this->get(route('users.show', $this->actor))->assertOk();
    $this->get(route('users.affiliations.edit', [$this->actor, $this->office]))->assertOk();
    $originalAccount = $this->actor->fresh()->getAttributes();
    $other = Affiliation::factory()->global()->for($this->actor)->create();
    $originalOther = $other->fresh()->getAttributes();
    $this->put(route('users.affiliations.update', [$this->actor, $this->office]), [
        'email' => ' NEW@EXAMPLE.TEST ', 'registration_number' => ' OFFICE-002 ',
    ])->assertSessionHasNoErrors()->assertRedirect(route('users.show', $this->actor));
    expect($this->office->fresh()->email)->toBe('new@example.test')->and($this->office->fresh()->registration_number)->toBe('OFFICE-002')
        ->and($this->office->fresh()->campus_id)->toBe($this->office->campus_id)->and($this->office->fresh()->deactivated_at)->toBeNull()
        ->and($this->actor->fresh()->getAttributes())->toBe($originalAccount)->and($other->fresh()->getAttributes())->toBe($originalOther)
        ->and(Activity::forSubject($this->office)->where('event', 'updated')->sole()->causer_id)->toBe($this->office->id)
        ->and(EmailDeliveryAttempt::count())->toBe(0);
});

test('own affiliation editing rejects invalid contact registration ownership and immutable fields', function (string $field) {
    $payload = ['email' => 'new@example.test', 'registration_number' => 'OFFICE-002'];
    $payload[$field] = match ($field) {
        'email' => 'invalid',
        'registration_number' => Affiliation::factory()->server()->create()->registration_number,
        default => 'forged',
    };
    $this->put(route('users.affiliations.update', [$this->actor, $this->office]), $payload)->assertSessionHasErrors($field);
    expect($this->office->fresh()->email)->toBe('office@example.test')->and($this->office->fresh()->registration_number)->toBe('OFFICE-001');
})->with(['email', 'registration_number', 'type', 'campus_id', 'course_id', 'user_id', 'deactivated_at']);

test('office cannot remove its selected affiliation or reactivate an already active one', function (string $operation) {
    $data = ['confirmed' => 1, 'current_password' => 'password'];
    $route = route('users.affiliations.'.($operation === 'delete' ? 'destroy' : $operation), [$this->actor, $this->office]);
    if ($operation === 'delete') {
        $this->delete($route, $data)->assertForbidden();
    } else {
        $this->patch($route, $data)->assertForbidden();
    }
    Livewire::test('pages::users.show', ['user' => $this->actor])->call('openConfirmation', $this->office->id, $operation)->assertForbidden();
    expect(fn () => app(UpdateAdministrativeAffiliation::class)->handle($this->actor, $this->actor, $this->office, $operation, $data))
        ->toThrow(AuthorizationException::class);
    $this->assertModelExists($this->office);
    expect($this->office->fresh()->deactivated_at)->toBeNull()->and(EmailDeliveryAttempt::count())->toBe(0);
})->with(['deactivate', 'delete', 'reactivate']);

test('office cannot edit affiliations outside the selected campus even when they are its own', function (bool $own) {
    $other = $own
        ? Affiliation::factory()->server()->for($this->actor)->create()
        : Affiliation::factory()->server()->create();
    $this->put(route('users.affiliations.update', [$other->user, $other]), ['email' => 'new@example.test', 'registration_number' => 'NEW'])->assertForbidden();
    expect(fn () => app(UpdateAdministrativeAffiliation::class)->handle($this->actor, $other->user, $other, 'update', [
        'email' => 'new@example.test', 'registration_number' => 'NEW',
    ]))->toThrow(AuthorizationException::class);
    $this->get(route('users.affiliations.edit', [$other->user, $other]))->assertForbidden();
    Livewire::test('users.form-fields', ['user' => $other->user, 'affiliation' => $other])->assertForbidden();
})->with([true, false]);

test('own affiliation forms revoke editing after campus inactivity or a selected context switch', function (string $component, bool $switchContext) {
    $params = ['user' => $this->actor, 'affiliation' => $this->office];
    $test = Livewire::test($component, $params);
    if ($switchContext) {
        $other = Affiliation::factory()->server()->for($this->actor)->create();
        session()->put('active_affiliation_id', $other->id);
    } else {
        $this->office->campus->deactivate();
    }
    $test->call('$refresh')->assertForbidden();
    $this->put(route('users.affiliations.update', [$this->actor, $this->office]), ['email' => 'new@example.test', 'registration_number' => 'NEW'])->assertForbidden();
})->with(['pages::users.affiliations.edit', 'users.form-fields'])->with([true, false]);

test('own affiliation editing preserves submitted values after validation', function () {
    $url = route('users.affiliations.edit', [$this->actor, $this->office]);
    $this->from($url)->put(route('users.affiliations.update', [$this->actor, $this->office]), [
        'email' => 'invalid', 'registration_number' => 'OFFICE-CORRECTED',
    ])->assertSessionHasErrors('email');
    $this->get($url)->assertOk();
});

test('own affiliation update reauthorizes after acquiring record locks', function () {
    $other = Affiliation::factory()->server()->for($this->actor)->create();
    $triggered = false;
    DB::listen(function ($query) use ($other, &$triggered): void {
        if (! $triggered && str_contains($query->sql, '"users"') && str_contains($query->sql, 'for update')) {
            $triggered = true;
            session()->put('active_affiliation_id', $other->id);
        }
    });
    $this->put(route('users.affiliations.update', [$this->actor, $this->office]), ['email' => 'new@example.test', 'registration_number' => 'NEW'])->assertForbidden();
    expect($this->office->fresh()->email)->toBe('office@example.test')->and($this->office->fresh()->registration_number)->toBe('OFFICE-001');
});

test('office deactivates its own coordinator affiliation while preserving its current affiliation and course references', function () {
    $coordinator = Affiliation::factory()->for($this->actor)->for($this->office->campus)->create(['type' => AffiliationType::Coordinator]);
    $course = Course::factory()->for($this->office->campus)->create(['primary_coordinator_affiliation_id' => $coordinator->id]);
    Livewire::test('pages::users.show', ['user' => $this->actor])->call('openConfirmation', $coordinator->id, 'deactivate')
        ->assertSet('showConfirmation', true);
    $this->patch(route('users.affiliations.deactivate', [$this->actor, $coordinator]), ['confirmed' => 1, 'current_password' => 'password'])
        ->assertSessionHasNoErrors();
    expect($coordinator->fresh()->deactivated_at)->not->toBeNull()
        ->and($this->office->fresh()->deactivated_at)->toBeNull()
        ->and(session('active_affiliation_id'))->toBe($this->office->id)
        ->and($course->fresh()->primary_coordinator_affiliation_id)->toBe($coordinator->id)
        ->and(Activity::forSubject($coordinator)->where('event', 'updated')->sole()->causer_id)->toBe($this->office->id);
});

test('office deletes an unreferenced own coordinator affiliation through the action', function () {
    $coordinator = Affiliation::factory()->for($this->actor)->for($this->office->campus)->create(['type' => AffiliationType::Coordinator]);
    app(UpdateAdministrativeAffiliation::class)->handle($this->actor, $this->actor, $coordinator, 'delete', ['confirmed' => 1, 'current_password' => 'password']);
    $this->assertModelMissing($coordinator);
    $this->assertModelExists($this->office);
    $this->assertModelExists($this->actor);
});

test('office reactivates its own coordinator affiliation using confirmation without a password', function () {
    $coordinator = Affiliation::factory()->deactivated()->for($this->actor)->for($this->office->campus)->create(['type' => AffiliationType::Coordinator]);
    Livewire::test('pages::users.show', ['user' => $this->actor])->call('openConfirmation', $coordinator->id, 'reactivate')
        ->assertSet('showConfirmation', true);
    $this->patch(route('users.affiliations.reactivate', [$this->actor, $coordinator]), ['confirmed' => 1])->assertSessionHasNoErrors();
    expect($coordinator->fresh()->deactivated_at)->toBeNull()->and(EmailDeliveryAttempt::count())->toBe(0);
});

test('own affiliation reactivation still checks active duplicates and ownership scope', function () {
    $active = Affiliation::factory()->for($this->actor)->for($this->office->campus)->create(['type' => AffiliationType::Coordinator]);
    $inactive = Affiliation::factory()->deactivated()->for($this->actor)->for($this->office->campus)->create(['type' => AffiliationType::Coordinator]);
    $this->patch(route('users.affiliations.reactivate', [$this->actor, $inactive]), ['confirmed' => 1])->assertSessionHasErrors('type');
    expect(fn () => app(UpdateAdministrativeAffiliation::class)->handle($this->actor, $this->actor, $inactive, 'reactivate', ['confirmed' => 1]))
        ->toThrow(ValidationException::class);
    expect($inactive->fresh()->deactivated_at)->not->toBeNull();
    $foreign = Affiliation::factory()->deactivated()->for($this->actor)->create(['type' => AffiliationType::Coordinator]);
    $this->patch(route('users.affiliations.reactivate', [$this->actor, $foreign]), ['confirmed' => 1])->assertForbidden();
    $otherUser = User::factory()->create();
    $other = Affiliation::factory()->deactivated()->for($otherUser)->for($this->office->campus)->create(['type' => AffiliationType::Coordinator]);
    $this->patch(route('users.affiliations.reactivate', [$otherUser, $other]), ['confirmed' => 1])->assertForbidden();
    $this->assertModelExists($active);
});

test('own coordinator deletion still respects course references and context changes after opening confirmation', function () {
    $coordinator = Affiliation::factory()->for($this->actor)->for($this->office->campus)->create(['type' => AffiliationType::Coordinator]);
    Course::factory()->for($this->office->campus)->create(['primary_coordinator_affiliation_id' => $coordinator->id]);
    $this->delete(route('users.affiliations.destroy', [$this->actor, $coordinator]), ['confirmed' => 1, 'current_password' => 'password'])
        ->assertSessionHasErrors('affiliation');
    $component = Livewire::test('pages::users.show', ['user' => $this->actor])->call('openConfirmation', $coordinator->id, 'deactivate');
    session()->put('active_affiliation_id', $coordinator->id);
    $component->call('$refresh')->assertForbidden();
    $this->patch(route('users.affiliations.deactivate', [$this->actor, $coordinator]), ['confirmed' => 1, 'current_password' => 'password'])->assertForbidden();
    expect($coordinator->fresh()->deactivated_at)->toBeNull();
});
