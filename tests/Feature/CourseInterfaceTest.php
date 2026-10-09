<?php

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Internship;
use App\Models\InternshipRequest;
use App\Models\InternshipType;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->campus = Campus::factory()->create();
    $this->administrator = Affiliation::factory()->for($this->campus)->create(['type' => AffiliationType::CampusAdministrator]);
    $this->actingAs($this->administrator->user)->withSession(['active_affiliation_id' => $this->administrator->id]);
});

test('course pages render administrative navigation forms and optional custom selects', function () {
    $course = Course::factory()->for($this->campus)->create(['name' => 'Engenharia']);
    $this->get(route('courses.index'))->assertOk()->assertSee('Engenharia')->assertSee(route('courses.create'));
    $this->get(route('courses.create'))->assertOk()->assertSee('Nenhum coordenador elegível')
        ->assertSee('name="primary_coordinator_affiliation_id"', false)
        ->assertSee('name="secondary_coordinator_affiliation_id"', false)
        ->assertSee('data-select-option', false)->assertDontSee('name="campus_id"', false);
    $this->get(route('courses.show', $course))->assertOk()->assertSee('Engenharia')->assertSee('Não informado');
    $this->get(route('courses.show', $course))->assertSee('Disponibilidade do curso')
        ->assertSee('name="currentPassword"', false)
        ->assertSee('Sua senha atual')->assertSee('wire:submit="deactivateCourse"', false);
    $this->get(route('courses.edit', $course))->assertOk()->assertSee('Editar curso')->assertSee('value="Engenharia"', false);
    $this->get(route('dashboard'))->assertOk()->assertSee(route('courses.index'))->assertDontSee(route('audit.index'))->assertDontSee(route('email-logs.index'));
});

test('course navigation depends on the selected affiliation policy', function () {
    $system = Affiliation::factory()->global()->for($this->administrator->user)->create();
    $this->withSession(['active_affiliation_id' => $system->id]);
    $this->get(route('dashboard'))->assertOk()->assertDontSee(route('courses.index'));
});

test('course listing is scoped paginated and filters status without hiding the list while loading', function () {
    $courses = Course::factory()->for($this->campus)->count(16)->sequence(fn ($sequence) => ['name' => sprintf('Curso %02d', $sequence->index)])->create();
    $inactive = Course::factory()->for($this->campus)->deactivated()->create(['name' => 'Curso desativado local']);
    $other = Course::factory()->create(['name' => 'Curso sigiloso externo']);
    $component = Livewire::test('pages::courses.index')->assertSee($courses[0]->name)
        ->assertDontSee($courses[15]->name)->assertDontSee($other->name);
    expect($component->get('courses')->total())->toBe(17);
    $component->call('gotoPage', 2)->assertSee($courses[15]->name)->assertSee($inactive->name);
    $component->set('status', 'inactive')->assertSee($inactive->name)->assertDontSee($courses[0]->name);
    expect($component->get('courses')->total())->toBe(1);
    $component->set('status', 'active')->assertDontSee($inactive->name);
    expect($component->get('courses')->total())->toBe(16);
    $component->call('clearFilters')->assertSet('status', 'all')
        ->assertSee('Atualizando lista…')->assertSee('absolute -top-7', false)->assertDontSee('wire:loading.remove', false);
});

test('coordinator options only contain eligible affiliations in the selected campus', function () {
    $valid = Affiliation::factory()->for($this->campus)->create(['type' => AffiliationType::Coordinator]);
    $inactive = Affiliation::factory()->for($this->campus)->deactivated()->create(['type' => AffiliationType::Coordinator]);
    $other = Affiliation::factory()->create(['type' => AffiliationType::Coordinator]);
    $wrongType = Affiliation::factory()->for($this->campus)->server()->create();
    $component = Livewire::test('courses.form-fields');
    expect(array_keys($component->get('coordinatorOptions')))->toBe(['', $valid->id]);
    $component->assertSee($valid->user->name)->assertDontSee($inactive->user->name)
        ->assertDontSee($other->user->name)->assertDontSee($wrongType->user->name);
    expect($component->get('values'))->toMatchArray(['primary_coordinator_affiliation_id' => '', 'secondary_coordinator_affiliation_id' => '']);
});

test('editing preserves historical coordinator labels without offering inactive affiliations for assignment', function () {
    $coordinator = Affiliation::factory()->for($this->campus)->create(['type' => AffiliationType::Coordinator]);
    $course = Course::factory()->for($this->campus)->create(['primary_coordinator_affiliation_id' => $coordinator->id]);
    $coordinator->update(['deactivated_at' => now()]);
    $component = Livewire::test('courses.form-fields', ['course' => $course]);
    expect($component->get('coordinatorOptions'))->toBe(['' => 'Sem coordenador'])
        ->and($component->get('historicalLabels')['primary_coordinator_affiliation_id'])->toContain($coordinator->user->name, 'vínculo desativado');
    $component->assertSee('data-selected-value="'.$coordinator->id.'"', false);
    $this->get(route('courses.show', $course))->assertOk()->assertSee('referência histórica');
});

test('livewire course pages and selectors reauthorize deactivated affiliations on every request', function (string $componentName) {
    $course = Course::factory()->for($this->campus)->create();
    $parameters = in_array($componentName, ['pages::courses.show', 'pages::courses.edit'], true) ? ['course' => $course] : [];
    $component = Livewire::test($componentName, $parameters);
    $this->administrator->update(['deactivated_at' => now()]);
    $component->call('$refresh')->assertForbidden();
})->with(['pages::courses.index', 'pages::courses.create', 'pages::courses.edit', 'pages::courses.show', 'courses.form-fields']);

test('switching selected profile revokes already mounted course components', function (string $componentName) {
    $course = Course::factory()->for($this->campus)->create();
    $parameters = in_array($componentName, ['pages::courses.show', 'pages::courses.edit'], true) ? ['course' => $course] : [];
    $component = Livewire::test($componentName, $parameters);
    $system = Affiliation::factory()->for($this->administrator->user)->global()->create();
    session()->put('active_affiliation_id', $system->id);
    $component->call('$refresh')->assertForbidden();
})->with(['pages::courses.index', 'pages::courses.create', 'pages::courses.edit', 'pages::courses.show', 'courses.form-fields']);

test('course and selector identities are locked against client tampering', function (string $componentName, string $property) {
    $course = Course::factory()->for($this->campus)->create();
    expect(fn () => Livewire::test($componentName, ['course' => $course])->set($property, 999999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    ['pages::courses.show', 'courseId'],
    ['pages::courses.edit', 'courseId'],
    ['courses.form-fields', 'courseId'],
    ['courses.form-fields', 'campusId'],
]);

test('livewire refuses directly mounted courses from another campus', function (string $componentName) {
    $other = Course::factory()->create();
    Livewire::test($componentName, ['course' => $other])->assertForbidden();
})->with(['pages::courses.show', 'pages::courses.edit', 'courses.form-fields']);

test('switching selected campus revokes existing course details edits and selectors', function (string $componentName) {
    $course = Course::factory()->for($this->campus)->create();
    $component = Livewire::test($componentName, ['course' => $course]);
    $other = Affiliation::factory()->for($this->administrator->user)->create(['type' => AffiliationType::CampusAdministrator]);
    session()->put('active_affiliation_id', $other->id);
    $component->call('$refresh')->assertForbidden();
})->with(['pages::courses.show', 'pages::courses.edit', 'courses.form-fields']);

test('new course selectors cannot continue after switching selected campus', function () {
    $component = Livewire::test('courses.form-fields');
    $other = Affiliation::factory()->for($this->administrator->user)->create(['type' => AffiliationType::CampusAdministrator]);
    session()->put('active_affiliation_id', $other->id);
    $component->call('$refresh')->assertForbidden();
});

test('livewire course lifecycle confirms transitions and preserves student history', function () {
    $course = Course::factory()->for($this->campus)->create();
    $student = Affiliation::factory()->student()->for($this->campus)->create(['course_id' => $course->id]);
    Livewire::test('pages::courses.show', ['course' => $course])
        ->set('showDeactivation', true)->set('currentPassword', 'password')->call('deactivateCourse')
        ->assertSet('showDeactivation', false)->assertSee('Curso desativado')->assertDispatched('toast-show')
        ->set('showReactivation', true)->call('reactivateCourse')
        ->assertSet('showReactivation', false)->assertDispatched('toast-show');
    expect($course->fresh()->deactivated_at)->toBeNull()->and($student->fresh()->course_id)->toBe($course->id);
    $activities = Activity::query()->where('subject_type', Course::class)->where('subject_id', $course->id)->where('event', 'updated')->get();
    expect($activities)->toHaveCount(2);
    foreach ($activities as $activity) {
        expect($activity->causer_type)->toBe(Affiliation::class)->and($activity->causer_id)->toBe($this->administrator->id);
    }
});

test('course deactivation modal keeps wrong-password feedback open and clears the password when closed', function () {
    $course = Course::factory()->for($this->campus)->create();

    $component = Livewire::test('pages::courses.show', ['course' => $course])
        ->set('showDeactivation', true)
        ->set('currentPassword', 'senha-incorreta')
        ->call('deactivateCourse')
        ->assertHasErrors(['currentPassword' => 'current_password'])
        ->assertSet('showDeactivation', true)
        ->assertSet('currentPassword', 'senha-incorreta');

    expect(substr_count($component->html(), 'A senha informada está incorreta.'))->toBe(1);

    $component->set('showDeactivation', false)
        ->assertSet('currentPassword', '')
        ->assertHasNoErrors();

    expect($course->fresh()->deactivated_at)->toBeNull();
});

test('livewire lifecycle actions reject a changed selected campus', function (string $operation) {
    $course = Course::factory()->for($this->campus)->create(['deactivated_at' => $operation === 'reactivateCourse' ? now() : null]);
    $component = Livewire::test('pages::courses.show', ['course' => $course]);
    $other = Affiliation::factory()->for($this->administrator->user)->create(['type' => AffiliationType::CampusAdministrator]);
    session()->put('active_affiliation_id', $other->id);
    $component->call($operation)->assertForbidden();
    expect($course->fresh()->deactivated_at === null)->toBe($operation === 'deactivateCourse');
})->with(['deactivateCourse', 'reactivateCourse']);

test('livewire lifecycle actions freeze immediately when campus is deactivated', function (string $operation) {
    $course = Course::factory()->for($this->campus)->create(['deactivated_at' => $operation === 'reactivateCourse' ? now() : null]);
    $component = Livewire::test('pages::courses.show', ['course' => $course]);
    $this->campus->deactivate();
    $component->call($operation)->assertForbidden();
    expect($course->fresh()->deactivated_at === null)->toBe($operation === 'deactivateCourse');
})->with(['deactivateCourse', 'reactivateCourse']);

test('inactive campus is read only and freezes mounted form fields and edits', function () {
    $course = Course::factory()->for($this->campus)->create();
    $edit = Livewire::test('pages::courses.edit', ['course' => $course]);
    $fields = Livewire::test('courses.form-fields', ['course' => $course]);
    $this->campus->deactivate();
    $edit->call('$refresh')->assertForbidden();
    $fields->call('$refresh')->assertForbidden();
    Livewire::test('pages::courses.index')->assertSee('Campus somente para leitura')->assertDontSee('Cadastrar curso');
    Livewire::test('pages::courses.show', ['course' => $course])->assertSee('Campus somente para leitura')
        ->assertDontSee('Editar curso')->assertDontSee('Confirmar desativação')->assertDontSee('Confirmar reativação');
});

test('invalid course submissions preserve filled fields and render validation messages once', function () {
    $coordinator = Affiliation::factory()->for($this->campus)->create(['type' => AffiliationType::Coordinator]);
    $this->from(route('courses.create'))->post(route('courses.store'), [
        'name' => '',
        'primary_coordinator_affiliation_id' => $coordinator->id,
        'secondary_coordinator_affiliation_id' => $coordinator->id,
    ])->assertRedirect(route('courses.create'))->assertSessionHasErrors(['name', 'secondary_coordinator_affiliation_id']);
    $this->withCookie(config('session.cookie'), session()->getId());
    $response = $this->get(route('courses.create'))->assertOk()
        ->assertSee('data-selected-value="'.$coordinator->id.'"', false);
    expect(substr_count($response->getContent(), 'Informe o nome do curso.'))->toBe(1)
        ->and(substr_count($response->getContent(), 'Os coordenadores principal e secundário devem ser distintos.'))->toBe(1);
});

test('coordinator eligibility is rechecked when submitting a previously opened form', function () {
    $coordinator = Affiliation::factory()->for($this->campus)->create(['type' => AffiliationType::Coordinator]);
    Livewire::test('courses.form-fields')->assertSee($coordinator->user->name);
    $coordinator->update(['deactivated_at' => now()]);
    $this->post(route('courses.store'), ['name' => 'Curso', 'primary_coordinator_affiliation_id' => $coordinator->id])
        ->assertSessionHasErrors('primary_coordinator_affiliation_id');
    expect(Course::query()->count())->toBe(0);
});

test('course deletion is offered only while no linked records exist', function (string $relation) {
    $course = Course::factory()->for($this->campus)->create();

    if ($relation === 'student affiliation') {
        Affiliation::factory()->student()->for($this->campus)->create(['course_id' => $course->id]);
    } elseif ($relation === 'internship type') {
        InternshipType::factory()->for($course)->create();
    } elseif ($relation === 'internship request') {
        $student = Affiliation::factory()->student()->for($this->campus)->create(['course_id' => $course->id]);
        InternshipRequest::factory()->create(['affiliation_id' => $student->id, 'course_id' => $course->id]);
    } else {
        Internship::factory()->create(['course_id' => $course->id]);
    }

    Livewire::test('pages::courses.show', ['course' => $course])
        ->assertDontSee('data-course-delete-action', false);
    expect($course->hasLinkedRecords())->toBeTrue();
})->with(['student affiliation', 'internship type', 'internship request', 'internship']);

test('course details offer deletion and require an explicit confirmation', function () {
    $course = Course::factory()->for($this->campus)->create();
    Livewire::test('pages::courses.show', ['course' => $course])
        ->assertSee('data-course-delete-action', false)
        ->assertSee('wire:submit="deleteCourse"', false)
        ->assertSee('Confirme sua senha para continuar.');
});

test('a campus administrator cannot delete a course outside the selected campus', function () {
    $course = Course::factory()->create();
    Livewire::test('pages::courses.show', ['course' => $course])->assertForbidden();
});
