<?php

use App\Actions\ManageInternshipType;
use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Internship;
use App\Models\InternshipRequest;
use App\Models\InternshipType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->campus = Campus::factory()->create();
    $this->office = Affiliation::factory()->for($this->campus)->create(['type' => AffiliationType::InternshipOffice]);
    $this->course = Course::factory()->for($this->campus)->create();
    $this->data = Arr::except(InternshipType::factory()->for($this->course)->raw(), ['deactivated_at']);
    $this->actingAs($this->office->user)->withSession(['active_affiliation_id' => $this->office->id]);
});

test('internship office manages type configuration and lifecycle with the selected affiliation as audit actor', function () {
    $this->post(route('internship-types.store'), $this->data)->assertRedirect();
    $type = InternshipType::query()->sole();
    $otherCourse = Course::factory()->for($this->campus)->create();
    $updated = [...$this->data, 'course_id' => $otherCourse->id, 'name' => 'Estágio obrigatório',
        'very_good_value' => 3.5, 'satisfactory_value' => 1.5, 'unsatisfactory_value' => 1,
        'required_hours' => 400, 'max_daily_hours' => 8, 'max_weekly_hours' => 40, 'safety_margin_days' => 0];
    $this->put(route('internship-types.update', $type), $updated)->assertRedirect(route('internship-types.show', $type));
    $this->patch(route('internship-types.deactivate', $type), ['current_password' => 'password'])->assertRedirect(route('internship-types.show', $type));
    expect($type->fresh()->deactivated_at)->not->toBeNull();
    $this->patch(route('internship-types.reactivate', $type))->assertRedirect(route('internship-types.show', $type));
    expect($type->fresh()->deactivated_at)->toBeNull()
        ->and($type->fresh()->course_id)->toBe($otherCourse->id)
        ->and($type->fresh()->required_hours)->toBe(400)
        ->and($type->fresh()->very_good_value)->toBe('3.5')
        ->and($type->fresh()->unsatisfactory_value)->toBe('1.0')
        ->and($type->fresh()->max_daily_hours)->toBe(8)
        ->and($type->fresh()->max_weekly_hours)->toBe(40)
        ->and($type->fresh()->safety_margin_days)->toBe(0);
    $activities = Activity::query()->where('subject_type', InternshipType::class)->where('subject_id', $type->id)->orderBy('id')->get();
    expect($activities)->toHaveCount(4);
    foreach ($activities as $activity) {
        expect($activity->causer_type)->toBe(Affiliation::class)
            ->and($activity->causer_id)->toBe($this->office->id)
            ->and($activity->properties['affiliation_id'])->toBe($this->office->id)
            ->and($activity->attribute_changes->toJson())->not->toContain('password', 'token');
    }
    expect($activities[1]->attribute_changes['old']['course_id'])->toBe($this->course->id);
});

test('type forms enforce the existing domain validation on creation and update', function (string $field, mixed $value, ?string $error = null, array $otherValues = []) {
    $type = InternshipType::factory()->for($this->course)->create();
    $attributes = $type->fresh()->getAttributes();
    $data = [...$this->data, ...$otherValues, $field => $value];
    $this->post(route('internship-types.store'), $data)->assertSessionHasErrors($error ?? $field);
    $this->put(route('internship-types.update', $type), $data)->assertSessionHasErrors($error ?? $field);
    expect($type->fresh()->getAttributes())->toBe($attributes)
        ->and(InternshipType::query()->count())->toBe(1);
})->with([
    'name missing' => ['name', ''],
    'name too long' => ['name', str_repeat('a', 256)],
    'name array' => ['name', ['injected']],
    'hours missing' => ['required_hours', null],
    'hours positive' => ['required_hours', 0],
    'hours integer' => ['required_hours', 300.5],
    'supervisor weight positive' => ['supervisor_evaluation_weight', 0],
    'report weight positive' => ['report_weight', 0],
    'presentation weight positive' => ['presentation_weight', 0],
    'weight integer' => ['report_weight', 3.5],
    'weight required' => ['presentation_weight', null],
    'weights total ten' => ['report_weight', 4, 'supervisor_evaluation_weight'],
    'concept precision' => ['good_value', 2.55],
    'concept negative' => ['unsatisfactory_value', -0.1],
    'concept required' => ['satisfactory_value', null],
    'concept upper bound' => ['very_good_value', 5],
    'concept equals excellent' => ['very_good_value', 4],
    'concept repeated' => ['good_value', 3],
    'concept ascending' => ['satisfactory_value', 2.5],
    'unsatisfactory equals satisfactory' => ['unsatisfactory_value', 1],
    'daily minimum' => ['max_daily_hours', 5],
    'daily maximum' => ['max_daily_hours', 25],
    'daily above weekly' => ['max_weekly_hours', 23, 'max_daily_hours', ['max_daily_hours' => 24]],
    'weekly minimum' => ['max_weekly_hours', 29],
    'weekly maximum' => ['max_weekly_hours', 41],
    'weekly integer' => ['max_weekly_hours', 30.5],
    'margin negative' => ['safety_margin_days', -1],
    'margin integer' => ['safety_margin_days', 0.5],
]);

test('type forms and actions reject unavailable or external course identities', function (string $kind) {
    $courseId = match ($kind) {
        'external' => Course::factory()->create()->id,
        'inactive' => Course::factory()->for($this->campus)->deactivated()->create()->id,
        'missing' => 9999999,
        'malformed' => 'injected',
        'array' => [9999999],
        'oversized' => '99999999999999999999999999999',
    };
    $type = InternshipType::factory()->for($this->course)->create();
    $data = [...$this->data, 'course_id' => $courseId];
    $this->post(route('internship-types.store'), $data)->assertSessionHasErrors('course_id');
    $this->put(route('internship-types.update', $type), $data)->assertSessionHasErrors('course_id');
    expect(fn () => app(ManageInternshipType::class)->handle($this->office->user, $type, 'update', $data))
        ->toThrow(ValidationException::class);
    expect($type->fresh()->course_id)->toBe($this->course->id)->and(InternshipType::query()->count())->toBe(1);
})->with(['external', 'inactive', 'missing', 'malformed', 'array', 'oversized']);

test('type forms cannot set campus scope status or historical snapshots', function (string $field) {
    $type = InternshipType::factory()->for($this->course)->create();
    $data = [...$this->data, $field => 9999999];
    $this->post(route('internship-types.store'), $data)->assertSessionHasErrors($field);
    $this->put(route('internship-types.update', $type), $data)->assertSessionHasErrors($field);
    expect(fn () => app(ManageInternshipType::class)->handle($this->office->user, $type, 'update', $data))->toThrow(ValidationException::class);
    expect($type->fresh()->deactivated_at)->toBeNull()->and($type->fresh()->course_id)->toBe($this->course->id);
})->with(['campus_id', 'id', 'scope', 'deactivated_at', 'excellent_value', 'rules', 'internship_type_snapshot']);

test('only the selected internship office profile grants type access', function (AffiliationType $profile) {
    $factory = match ($profile) {
        AffiliationType::Student => Affiliation::factory()->student(),
        AffiliationType::Supervisor => Affiliation::factory()->supervisor(),
        default => Affiliation::factory(),
    };
    $selected = $factory->for($this->office->user)->create([
        'type' => $profile, 'campus_id' => $profile === AffiliationType::SystemAdministrator ? null : $this->campus->id,
    ]);
    $this->withSession(['active_affiliation_id' => $selected->id]);
    $type = InternshipType::factory()->for($this->course)->create();
    foreach (['viewAny', 'create'] as $ability) {
        expect(Gate::allows($ability, InternshipType::class))->toBeFalse();
    }
    foreach (['view', 'update', 'deactivate', 'reactivate', 'delete'] as $ability) {
        expect(Gate::allows($ability, $type))->toBeFalse();
    }
    $this->get(route('internship-types.index'))->assertForbidden();
    $this->get(route('internship-types.create'))->assertForbidden();
    $this->get(route('internship-types.show', $type))->assertForbidden();
    $this->get(route('internship-types.edit', $type))->assertForbidden();
    $this->post(route('internship-types.store'), $this->data)->assertForbidden();
    $this->put(route('internship-types.update', $type), $this->data)->assertForbidden();
    $this->patch(route('internship-types.deactivate', $type), ['current_password' => 'password'])->assertForbidden();
    $this->patch(route('internship-types.reactivate', $type))->assertForbidden();
})->with(array_filter(AffiliationType::cases(), fn (AffiliationType $profile): bool => $profile !== AffiliationType::InternshipOffice));

test('another campus type cannot be consulted updated or transitioned by its identity', function () {
    $type = InternshipType::factory()->create(['name' => 'Tipo externo']);
    expect(Gate::allows('delete', $type))->toBeFalse();
    $this->get(route('internship-types.show', $type))->assertForbidden();
    $this->get(route('internship-types.edit', $type))->assertForbidden();
    $this->put(route('internship-types.update', $type), $this->data)->assertForbidden();
    $this->patch(route('internship-types.deactivate', $type), ['current_password' => 'password'])->assertForbidden();
    expect(fn () => app(ManageInternshipType::class)->handle($this->office->user, $type, 'update', $this->data))->toThrow(AuthorizationException::class);
    expect(fn () => app(ManageInternshipType::class)->handle($this->office->user, $type, 'delete'))->toThrow(AuthorizationException::class);
    $type->update(['deactivated_at' => now()]);
    $this->patch(route('internship-types.reactivate', $type))->assertForbidden();
    expect($type->fresh()->name)->toBe('Tipo externo')->and($type->fresh()->deactivated_at)->not->toBeNull();
});

test('inactive campus permits type reads but freezes every write', function () {
    $type = InternshipType::factory()->for($this->course)->create();
    $inactive = InternshipType::factory()->for($this->course)->deactivated()->create();
    $this->campus->deactivate();
    $this->get(route('internship-types.index'))->assertSuccessful();
    $this->get(route('internship-types.show', $type))->assertSuccessful();
    $this->get(route('internship-types.create'))->assertForbidden();
    $this->get(route('internship-types.edit', $type))->assertForbidden();
    $this->post(route('internship-types.store'), $this->data)->assertForbidden();
    $this->put(route('internship-types.update', $type), $this->data)->assertForbidden();
    $this->patch(route('internship-types.deactivate', $type), ['current_password' => 'password'])->assertForbidden();
    $this->patch(route('internship-types.reactivate', $inactive))->assertForbidden();
    expect(fn () => app(ManageInternshipType::class)->handle($this->office->user, $type, 'update', $this->data))->toThrow(AuthorizationException::class);
});

test('inactive courses may be retained on edits and deactivation but must be active on reactivation', function () {
    $type = InternshipType::factory()->for($this->course)->create();
    $this->course->update(['deactivated_at' => now()]);
    $this->put(route('internship-types.update', $type), [...$this->data, 'name' => 'Tipo histórico'])->assertRedirect();
    $this->patch(route('internship-types.deactivate', $type), ['current_password' => 'password'])->assertRedirect();
    $this->put(route('internship-types.update', $type), [...$this->data, 'required_hours' => 400])->assertRedirect();
    $this->patch(route('internship-types.reactivate', $type))->assertSessionHasErrors('course_id');
    expect($type->fresh()->deactivated_at)->not->toBeNull()->and($type->fresh()->required_hours)->toBe(400);
    $newCourse = Course::factory()->for($this->campus)->create();
    $this->put(route('internship-types.update', $type), [...$this->data, 'course_id' => $newCourse->id])->assertRedirect();
    $this->patch(route('internship-types.reactivate', $type))->assertRedirect();
    expect($type->fresh()->deactivated_at)->toBeNull()->and($type->fresh()->course_id)->toBe($newCourse->id);
});

test('lifecycle requires password for deactivation and rejects repeated transitions', function () {
    $type = InternshipType::factory()->for($this->course)->create();
    $this->patch(route('internship-types.reactivate', $type))->assertForbidden();
    $this->patch(route('internship-types.deactivate', $type))->assertSessionHasErrors('current_password');
    $this->patch(route('internship-types.deactivate', $type), ['current_password' => 'wrong'])->assertSessionHasErrors('current_password')
        ->assertSessionMissing('_old_input.current_password');
    expect(fn () => app(ManageInternshipType::class)->handle($this->office->user, $type, 'deactivate', ['current_password' => 'wrong']))->toThrow(ValidationException::class);
    expect($type->fresh()->deactivated_at)->toBeNull();
    $this->patch(route('internship-types.deactivate', $type), ['current_password' => 'password'])->assertRedirect();
    $this->patch(route('internship-types.deactivate', $type), ['current_password' => 'password'])->assertForbidden();
    $this->patch(route('internship-types.reactivate', $type), ['course_id' => $this->course->id])->assertSessionHasErrors('course_id');
    expect($type->fresh()->deactivated_at)->not->toBeNull();
});

test('an unlinked internship type can be deleted through its selected campus context', function () {
    $type = InternshipType::factory()->for($this->course)->create();

    expect(Gate::allows('delete', $type))->toBeTrue();
    $manage = app(ManageInternshipType::class);
    expect(fn () => $manage->handle($this->office->user, $type, 'delete'))->toThrow(ValidationException::class);
    expect(fn () => $manage->handle($this->office->user, $type, 'delete', ['current_password' => 'wrong']))->toThrow(ValidationException::class);

    Livewire::test('pages::internship-types.show', ['internshipType' => $type])

        ->set('showDeletion', true)

        ->assertSee('wire:model="deletionPassword"', false)
        ->call('deleteInternshipType')->assertHasErrors(['deletionPassword' => 'required'])
        ->set('deletionPassword', 'wrong')->call('deleteInternshipType')
        ->assertHasErrors(['deletionPassword' => 'current_password'])
        ->set('showDeletion', false)->assertSet('deletionPassword', '')
        ->set('showDeletion', true)->set('deletionPassword', 'password')
        ->call('deleteInternshipType')
        ->assertRedirect(route('internship-types.index'));

    expect(InternshipType::query()->whereKey($type->id)->exists())->toBeFalse();
});

test('a type linked to an internship or request cannot be deleted', function (string $linkedRecord) {
    $type = InternshipType::factory()->for($this->course)->create();

    if ($linkedRecord === 'internship') {
        Internship::factory()->create(['course_id' => $this->course->id, 'internship_type_id' => $type->id]);
    } else {
        InternshipRequest::factory()->create(['internship_type_id' => $type->id]);
    }

    expect(Gate::allows('delete', $type))->toBeFalse();

    Livewire::test('pages::internship-types.show', ['internshipType' => $type])

        ->call('deleteInternshipType')
        ->assertForbidden();

    expect(fn () => app(ManageInternshipType::class)->handle($this->office->user, $type, 'delete'))
        ->toThrow(AuthorizationException::class)
        ->and($type->fresh())->not->toBeNull();
})->with(['internship', 'request']);

test('type action reloads a stale target and rechecks the selected affiliation', function () {
    $type = InternshipType::factory()->for($this->course)->create();
    $otherCourse = Course::factory()->create();
    $type->fresh()->update(['course_id' => $otherCourse->id]);
    expect(fn () => app(ManageInternshipType::class)->handle($this->office->user, $type, 'update', $this->data))->toThrow(AuthorizationException::class);
    expect($type->fresh()->course_id)->toBe($otherCourse->id);
    $local = InternshipType::factory()->for($this->course)->create();
    $this->office->update(['deactivated_at' => now()]);
    expect(fn () => app(ManageInternshipType::class)->handle($this->office->user, $local, 'update', $this->data))->toThrow(AuthorizationException::class);
});

test('changing type rules course and lifecycle preserves a formalized internship snapshot and dates', function () {
    $type = InternshipType::factory()->for($this->course)->create();
    $snapshot = ['name' => $type->name, 'rules' => Arr::except($this->data, ['name', 'course_id'])];
    $internship = Internship::factory()->released()->create([
        'course_id' => $this->course->id, 'internship_type_id' => $type->id, 'internship_type_snapshot' => $snapshot,
    ]);
    $snapshot = $internship->fresh()->internship_type_snapshot;
    $attributes = $internship->fresh()->getAttributes();
    $newCourse = Course::factory()->for($this->campus)->create();
    $this->put(route('internship-types.update', $type), [...$this->data, 'course_id' => $newCourse->id, 'required_hours' => 600, 'safety_margin_days' => 10])->assertRedirect();
    $this->patch(route('internship-types.deactivate', $type), ['current_password' => 'password'])->assertRedirect();
    $this->patch(route('internship-types.reactivate', $type))->assertRedirect();
    expect($internship->fresh()->getAttributes())->toBe($attributes)
        ->and($internship->fresh()->internship_type_snapshot)->toBe($snapshot);
});

test('invalid selected affiliations grant no type policy permissions', function (string $kind) {
    $selected = $this->office;
    if ($kind === 'inactive') {
        $selected->update(['deactivated_at' => now()]);
    } elseif ($kind === 'foreign') {
        $selected = Affiliation::factory()->for($this->campus)->create(['type' => AffiliationType::InternshipOffice]);
    }
    session()->put('active_affiliation_id', $kind === 'missing' ? 9999999 : $selected->id);
    expect(Gate::allows('viewAny', InternshipType::class))->toBeFalse()
        ->and(Gate::allows('create', InternshipType::class))->toBeFalse();
})->with(['inactive', 'foreign', 'missing']);

test('guests are redirected to login from type pages and writes', function () {
    auth()->logout();
    $type = InternshipType::factory()->for($this->course)->create();
    $this->get(route('internship-types.index'))->assertRedirect(route('login'));
    $this->get(route('internship-types.show', $type))->assertRedirect(route('login'));
    $this->post(route('internship-types.store'), $this->data)->assertRedirect(route('login'));
});

test('decimal concepts accept comma separators on creation and editing without weakening precision rules', function () {
    $data = [...$this->data, 'very_good_value' => '3,5', 'good_value' => '2,5', 'satisfactory_value' => '1,5', 'unsatisfactory_value' => '0,5'];
    $this->post(route('internship-types.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
    $type = InternshipType::query()->sole();
    expect($type->very_good_value)->toBe('3.5')->and($type->unsatisfactory_value)->toBe('0.5');
    $this->put(route('internship-types.update', $type), [...$data, 'good_value' => '2,0'])->assertSessionHasNoErrors()->assertRedirect();
    expect($type->fresh()->good_value)->toBe('2.0');
    foreach (['2,55', '2,,5', '2.5,0'] as $invalid) {
        $this->put(route('internship-types.update', $type), [...$data, 'good_value' => $invalid])->assertSessionHasErrors('good_value');
        expect($type->fresh()->good_value)->toBe('2.0');
    }
});
