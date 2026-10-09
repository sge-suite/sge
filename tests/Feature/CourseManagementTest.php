<?php

use App\Actions\ManageCourse;
use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->campus = Campus::factory()->create();
    $this->administrator = Affiliation::factory()->for($this->campus)->create(['type' => AffiliationType::CampusAdministrator]);
    $this->actingAs($this->administrator->user)->withSession(['active_affiliation_id' => $this->administrator->id]);
});

test('campus administrators manage courses using their selected campus and existing audit actor', function () {
    $this->post(route('courses.store'), ['name' => 'Técnico em Informática'])
        ->assertRedirect();
    $course = Course::query()->sole();
    expect($course->campus_id)->toBe($this->campus->id)
        ->and($course->primary_coordinator_affiliation_id)->toBeNull()
        ->and($course->secondary_coordinator_affiliation_id)->toBeNull();

    $this->put(route('courses.update', $course), ['name' => 'Informática'])
        ->assertRedirect(route('courses.show', $course));
    $this->patch(route('courses.deactivate', $course), ['current_password' => 'password'])->assertRedirect(route('courses.show', $course));
    expect($course->fresh()->deactivated_at)->not->toBeNull();
    $this->patch(route('courses.reactivate', $course))->assertRedirect(route('courses.show', $course));
    expect($course->fresh()->deactivated_at)->toBeNull()->and($course->fresh()->name)->toBe('Informática');

    $activities = Activity::query()->where('subject_type', Course::class)->where('subject_id', $course->id)->orderBy('id')->get();
    expect($activities)->toHaveCount(4);
    foreach ($activities as $activity) {
        expect($activity->causer_type)->toBe(Affiliation::class)
            ->and($activity->causer_id)->toBe($this->administrator->id)
            ->and($activity->properties['affiliation_id'])->toBe($this->administrator->id)
            ->and($activity->properties['user_id'])->toBe($this->administrator->user_id)
            ->and($activity->attribute_changes->toJson())->not->toContain('password', 'token', 'last_used_at');
    }
    expect($activities[1]->attribute_changes['old']['name'])->toBe('Técnico em Informática')
        ->and($activities[2]->attribute_changes['attributes']['deactivated_at'])->not->toBeNull()
        ->and($activities[3]->attribute_changes['attributes']['deactivated_at'])->toBeNull();
});

test('courses may have the same name and either coordinator slot may be empty', function () {
    $coordinator = Affiliation::factory()->for($this->campus)->create(['type' => AffiliationType::Coordinator]);
    foreach ([[], ['primary_coordinator_affiliation_id' => $coordinator->id], ['secondary_coordinator_affiliation_id' => $coordinator->id]] as $slots) {
        $this->post(route('courses.store'), ['name' => 'Mesmo nome', ...$slots])->assertRedirect();
    }
    expect(Course::query()->where('campus_id', $this->campus->id)->count())->toBe(3);
});

test('assigns and replaces two distinct active coordinator affiliations of the same campus', function () {
    $coordinators = Affiliation::factory()->for($this->campus)->count(3)->create(['type' => AffiliationType::Coordinator]);
    $this->post(route('courses.store'), [
        'name' => 'Curso coordenado',
        'primary_coordinator_affiliation_id' => $coordinators[0]->id,
        'secondary_coordinator_affiliation_id' => $coordinators[1]->id,
    ])->assertRedirect();
    $course = Course::query()->sole();
    $this->put(route('courses.update', $course), [
        'name' => 'Curso atualizado',
        'primary_coordinator_affiliation_id' => $coordinators[2]->id,
        'secondary_coordinator_affiliation_id' => '',
    ])->assertRedirect();
    expect($course->fresh()->primary_coordinator_affiliation_id)->toBe($coordinators[2]->id)
        ->and($course->fresh()->secondary_coordinator_affiliation_id)->toBeNull();
});

test('rejects invalid coordinator affiliations on creation and update', function (string $invalidKind, string $slot) {
    $invalidId = match ($invalidKind) {
        'other campus' => Affiliation::factory()->create(['type' => AffiliationType::Coordinator])->id,
        'inactive' => Affiliation::factory()->for($this->campus)->deactivated()->create(['type' => AffiliationType::Coordinator])->id,
        'wrong type' => Affiliation::factory()->for($this->campus)->server()->create()->id,
        'missing' => 9999999,
        'malformed' => 'invalid-id',
    };
    $data = ['name' => 'Curso inválido', $slot => $invalidId];
    $count = Activity::query()->where('subject_type', Course::class)->count();
    $this->post(route('courses.store'), $data)->assertSessionHasErrors($slot);
    expect(Course::query()->count())->toBe(0)
        ->and(Activity::query()->where('subject_type', Course::class)->count())->toBe($count);
    $course = Course::factory()->for($this->campus)->create(['name' => 'Preservado']);
    $this->put(route('courses.update', $course), $data)->assertSessionHasErrors($slot);
    expect($course->fresh()->name)->toBe('Preservado');
})->with(['other campus', 'inactive', 'wrong type', 'missing', 'malformed'])
    ->with(['primary_coordinator_affiliation_id', 'secondary_coordinator_affiliation_id']);

test('rejects repeated coordinator slots on creation and update', function () {
    $coordinator = Affiliation::factory()->for($this->campus)->create(['type' => AffiliationType::Coordinator]);
    $data = ['name' => 'Curso', 'primary_coordinator_affiliation_id' => $coordinator->id, 'secondary_coordinator_affiliation_id' => $coordinator->id];
    $this->post(route('courses.store'), $data)->assertSessionHasErrors('secondary_coordinator_affiliation_id');
    $course = Course::factory()->for($this->campus)->create();
    $this->put(route('courses.update', $course), $data)->assertSessionHasErrors('secondary_coordinator_affiliation_id');
    expect($course->fresh()->primary_coordinator_affiliation_id)->toBeNull();
});

test('requires a course name and enforces its existing maximum length', function (mixed $name) {
    $this->post(route('courses.store'), ['name' => $name])->assertSessionHasErrors('name');
    $course = Course::factory()->for($this->campus)->create();
    $this->put(route('courses.update', $course), ['name' => $name])->assertSessionHasErrors('name');
})->with(['empty' => '', 'too long' => str_repeat('a', 256), 'array' => [['injected']]]);

test('does not allow submitted campus identities or lifecycle fields', function (string $field) {
    $course = Course::factory()->for($this->campus)->create();
    $this->post(route('courses.store'), ['name' => 'Curso', $field => 999999])->assertSessionHasErrors($field);
    $this->put(route('courses.update', $course), ['name' => 'Curso', $field => 999999])->assertSessionHasErrors($field);
    expect(Course::query()->count())->toBe(1)->and($course->fresh()->campus_id)->toBe($this->campus->id);
})->with(['campus_id', 'id', 'deactivated_at', 'course_id']);

test('system administrators and other selected profiles receive no course management permissions', function (AffiliationType $type) {
    $factory = match ($type) {
        AffiliationType::Student => Affiliation::factory()->student(),
        AffiliationType::Supervisor => Affiliation::factory()->supervisor(),
        default => Affiliation::factory(),
    };
    $selected = $factory->for($this->administrator->user)->create([
        'type' => $type,
        'campus_id' => $type === AffiliationType::SystemAdministrator ? null : $this->campus->id,
    ]);
    $this->withSession(['active_affiliation_id' => $selected->id]);
    $course = Course::factory()->for($this->campus)->create();
    foreach (['viewAny', 'create'] as $ability) {
        expect(Gate::allows($ability, Course::class))->toBeFalse();
    }
    foreach (['view', 'update', 'deactivate', 'reactivate'] as $ability) {
        expect(Gate::allows($ability, $course))->toBeFalse();
    }
    $this->get(route('courses.index'))->assertForbidden();
    $this->get(route('courses.create'))->assertForbidden();
    $this->get(route('courses.show', $course))->assertForbidden();
    $this->get(route('courses.edit', $course))->assertForbidden();
    $this->post(route('courses.store'), ['name' => 'Curso'])->assertForbidden();
    $this->put(route('courses.update', $course), ['name' => 'Curso'])->assertForbidden();
    $this->patch(route('courses.deactivate', $course))->assertForbidden();
    $this->patch(route('courses.reactivate', $course))->assertForbidden();
})->with(array_filter(AffiliationType::cases(), fn (AffiliationType $type): bool => $type !== AffiliationType::CampusAdministrator));

test('selected deactivated or foreign affiliations cannot grant course access', function (string $contextKind) {
    $selected = $this->administrator;
    if ($contextKind === 'deactivated') {
        $selected->update(['deactivated_at' => now()]);
    } elseif ($contextKind === 'foreign') {
        $selected = Affiliation::factory()->for($this->campus)->create(['type' => AffiliationType::CampusAdministrator]);
    }
    $this->withSession(['active_affiliation_id' => $contextKind === 'missing' ? 9999999 : $selected->id]);
    expect(Gate::allows('viewAny', Course::class))->toBeFalse()->and(Gate::allows('create', Course::class))->toBeFalse();
    $this->get(route('courses.index'))->assertStatus($contextKind === 'deactivated' ? 403 : 302);
    $this->post(route('courses.store'), ['name' => 'Curso'])->assertStatus($contextKind === 'deactivated' ? 403 : 302);
    expect(Course::query()->count())->toBe(0);
})->with(['deactivated', 'foreign', 'missing']);

test('manipulating a course identity never exposes or changes another campus', function () {
    $course = Course::factory()->create(['name' => 'Curso de outro campus']);
    $this->get(route('courses.show', $course))->assertForbidden();
    $this->get(route('courses.edit', $course))->assertForbidden();
    $this->put(route('courses.update', $course), ['name' => 'Invadido'])->assertForbidden();
    $this->patch(route('courses.deactivate', $course))->assertForbidden();
    $course->update(['deactivated_at' => now()]);
    $this->patch(route('courses.reactivate', $course))->assertForbidden();
    expect($course->fresh()->name)->toBe('Curso de outro campus')->and($course->fresh()->deactivated_at)->not->toBeNull();
});

test('inactive campuses preserve authorized course reads and freeze all writes', function () {
    $course = Course::factory()->for($this->campus)->create();
    $inactiveCourse = Course::factory()->for($this->campus)->deactivated()->create();
    $this->campus->deactivate();
    $this->get(route('courses.index'))->assertOk();
    $this->get(route('courses.show', $course))->assertOk()->assertSee('Campus somente para leitura');
    $this->get(route('courses.create'))->assertForbidden();
    $this->get(route('courses.edit', $course))->assertForbidden();
    $this->post(route('courses.store'), ['name' => 'Curso'])->assertForbidden();
    $this->put(route('courses.update', $course), ['name' => 'Alterado'])->assertForbidden();
    $this->patch(route('courses.deactivate', $course))->assertForbidden();
    $this->patch(route('courses.reactivate', $inactiveCourse))->assertForbidden();
});

test('course deactivation requires the current password and does not persist it', function () {
    $course = Course::factory()->for($this->campus)->create();

    $this->from(route('courses.show', $course))
        ->patch(route('courses.deactivate', $course), ['current_password' => 'incorreta'])
        ->assertRedirect(route('courses.show', $course))
        ->assertSessionHasErrors('current_password')
        ->assertSessionMissing('_old_input.current_password');

    expect($course->fresh()->deactivated_at)->toBeNull();

    $this->withCookie(config('session.cookie'), session()->getId());
    $this->get(route('courses.show', $course))->assertOk()
        ->assertSee('Sua senha atual')->assertSee('&quot;showDeactivation&quot;:true', false);

    $this->patch(route('courses.deactivate', $course), ['current_password' => 'password'])
        ->assertRedirect(route('courses.show', $course));

    $activity = Activity::query()->where('subject_type', Course::class)->where('subject_id', $course->id)->where('event', 'updated')->sole();
    expect($course->fresh()->deactivated_at)->not->toBeNull()
        ->and($activity->causer->is($this->administrator))->toBeTrue()
        ->and($activity->properties->toJson())->not->toContain('password');
});

test('course deactivation action independently verifies the current password', function () {
    $course = Course::factory()->for($this->campus)->create();

    expect(fn () => app(ManageCourse::class)->handle($this->administrator->user, $course, 'deactivate', ['current_password' => 'incorrecta']))
        ->toThrow(ValidationException::class);

    expect($course->fresh()->deactivated_at)->toBeNull()
        ->and(Activity::query()->where('subject_type', Course::class)->where('subject_id', $course->id)->where('event', 'updated')->exists())->toBeFalse();
});

test('course lifecycle preserves student and coordinator history and existing student assignment rules', function () {
    $coordinator = Affiliation::factory()->for($this->campus)->create(['type' => AffiliationType::Coordinator]);
    $course = Course::factory()->for($this->campus)->create(['primary_coordinator_affiliation_id' => $coordinator->id]);
    $student = Affiliation::factory()->student()->for($this->campus)->create(['course_id' => $course->id]);
    $coordinator->update(['deactivated_at' => now()]);
    $this->put(route('courses.update', $course), ['name' => 'Nome atualizado', 'primary_coordinator_affiliation_id' => $coordinator->id])->assertRedirect();
    $this->patch(route('courses.deactivate', $course), ['current_password' => 'password'])->assertRedirect();
    expect($student->fresh()->course_id)->toBe($course->id)
        ->and($course->fresh()->primary_coordinator_affiliation_id)->toBe($coordinator->id);
    expect(fn () => Affiliation::factory()->student()->for($this->campus)->create(['course_id' => $course->id]))
        ->toThrow(ValidationException::class);
    $this->patch(route('courses.reactivate', $course))->assertRedirect();
    expect($course->fresh()->primary_coordinator_affiliation_id)->toBe($coordinator->id)
        ->and($student->fresh()->course_id)->toBe($course->id);
});

test('course actions revalidate campus state and selected profile before persisting', function () {
    $course = Course::factory()->for($this->campus)->create();
    expect(Gate::allows('update', $course))->toBeTrue();
    $this->campus->deactivate();
    expect(fn () => app(ManageCourse::class)->handle($this->administrator->user, $course, 'update', ['name' => 'Invadido']))
        ->toThrow(AuthorizationException::class);
    expect($course->fresh()->name)->toBe($course->name);
    $this->campus->reactivate();
    $system = Affiliation::factory()->global()->for($this->administrator->user)->create();
    session()->put('active_affiliation_id', $system->id);
    expect(fn () => app(ManageCourse::class)->handle($this->administrator->user, $course, 'update', ['name' => 'Invadido']))
        ->toThrow(AuthorizationException::class);
});

test('course management does not grant campus administrators access to audit or email logs', function () {
    $this->get(route('audit.index'))->assertForbidden();
    $this->get(route('email-logs.index'))->assertForbidden();
});

test('guests cannot access course pages or writes', function () {
    auth()->logout();
    $course = Course::factory()->for($this->campus)->create();
    $this->get(route('courses.index'))->assertRedirect(route('login'));
    $this->get(route('courses.show', $course))->assertRedirect(route('login'));
    $this->post(route('courses.store'), ['name' => 'Curso'])->assertRedirect(route('login'));
});

test('inactive courses keep their existing editing rules while their campus is active', function () {
    $course = Course::factory()->for($this->campus)->deactivated()->create();
    $this->put(route('courses.update', $course), ['name' => 'Nome corrigido'])->assertRedirect();
    expect($course->fresh()->name)->toBe('Nome corrigido')->and($course->fresh()->deactivated_at)->not->toBeNull();
});

test('deletes an unlinked course after password confirmation and records the active affiliation as actor', function () {
    $plainPassword = 'senha-correta-para-apagar-curso';
    $this->administrator->user->update(['password' => $plainPassword]);
    $course = Course::factory()->for($this->campus)->create();
    $courseId = $course->id;

    Livewire::test('pages::courses.show', ['course' => $course])
        ->set('showDeletion', true)
        ->set('deletionPassword', $plainPassword)
        ->call('deleteCourse')
        ->assertRedirect(route('courses.index'));

    $this->assertDatabaseMissing('courses', ['id' => $courseId]);
    $activity = Activity::query()->where('subject_type', Course::class)->where('subject_id', $courseId)->where('event', 'deleted')->sole();
    expect($activity->causer_type)->toBe(Affiliation::class)
        ->and($activity->causer_id)->toBe($this->administrator->id)
        ->and($activity->attribute_changes->toJson())->not->toContain($plainPassword);
});

test('course deletion rejects a wrong password and clears it when the modal closes', function () {
    $course = Course::factory()->for($this->campus)->create();
    Livewire::test('pages::courses.show', ['course' => $course])
        ->set('showDeletion', true)
        ->set('deletionPassword', 'senha-incorreta')
        ->call('deleteCourse')
        ->assertHasErrors(['deletionPassword' => 'current_password'])
        ->assertSet('showDeletion', true)
        ->call('closeDeletionModal')
        ->assertSet('showDeletion', false)
        ->assertSet('deletionPassword', '')
        ->assertHasNoErrors();
    $this->assertModelExists($course);
});

test('course deletion rechecks linked records created after the modal was opened', function () {
    $plainPassword = 'senha-correta-para-apagar-curso';
    $this->administrator->user->update(['password' => $plainPassword]);
    $course = Course::factory()->for($this->campus)->create();
    $component = Livewire::test('pages::courses.show', ['course' => $course])
        ->set('showDeletion', true)
        ->set('deletionPassword', $plainPassword);

    Affiliation::factory()->student()->for($this->campus)->create(['course_id' => $course->id]);

    $component->call('deleteCourse')->assertHasErrors('deletion')->assertSet('showDeletion', true);
    $this->assertModelExists($course);
});

test('course deletion revalidates the selected affiliation and campus state', function (string $state) {
    $plainPassword = 'senha-correta-para-apagar-curso';
    $this->administrator->user->update(['password' => $plainPassword]);
    $course = Course::factory()->for($this->campus)->create();
    $component = Livewire::test('pages::courses.show', ['course' => $course])
        ->set('showDeletion', true)
        ->set('deletionPassword', $plainPassword);

    if ($state === 'inactive campus') {
        $this->campus->deactivate();
    } else {
        $system = Affiliation::factory()->global()->for($this->administrator->user)->create();
        session()->put('active_affiliation_id', $system->id);
    }

    $component->call('deleteCourse')->assertForbidden();
    $this->assertModelExists($course);
})->with(['inactive campus', 'other selected profile']);
