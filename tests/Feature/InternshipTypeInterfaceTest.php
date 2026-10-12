<?php

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\InternshipType;
use Illuminate\Support\Arr;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->campus = Campus::factory()->create();
    $this->office = Affiliation::factory()->for($this->campus)->create(['type' => AffiliationType::InternshipOffice]);
    $this->course = Course::factory()->for($this->campus)->create(['name' => 'Curso local']);
    $this->actingAs($this->office->user)->withSession(['active_affiliation_id' => $this->office->id]);
});

test('type pages respond and expose the configuration fields', function () {
    $type = InternshipType::factory()->for($this->course)->create(['name' => 'Estágio obrigatório']);
    $this->get(route('internship-types.index'))->assertSuccessful();
    $this->get(route('internship-types.create'))->assertSuccessful()
        ->assertSee('name="course_id"', false)->assertSee('name="required_hours"', false)
        ->assertSee('name="unsatisfactory_value"', false)

        ->assertSee('name="max_daily_hours"', false)
        ->assertSee('name="max_weekly_hours"', false);
    $this->get(route('internship-types.show', $type))->assertSuccessful();
    $this->get(route('internship-types.edit', $type))->assertSuccessful();
    $this->get(route('dashboard'))->assertSuccessful();
    $system = Affiliation::factory()->global()->for($this->office->user)->create();
    $this->withSession(['active_affiliation_id' => $system->id]);
    $this->get(route('dashboard'))->assertSuccessful();
});

test('type listing isolates the campus paginates and filters active and inactive types', function () {
    $types = InternshipType::factory()->for($this->course)->count(16)->sequence(fn ($sequence) => ['name' => sprintf('Tipo %02d', $sequence->index)])->create();
    $inactive = InternshipType::factory()->for($this->course)->deactivated()->create(['name' => 'Tipo Z histórico']);
    $external = InternshipType::factory()->create(['name' => 'Tipo externo reservado']);
    $component = Livewire::test('pages::internship-types.index');
    expect($component->get('internshipTypes')->total())->toBe(17);
    $component->call('gotoPage', 2);
    expect($component->get('internshipTypes')->getCollection()->pluck('id'))->toContain($types[15]->id)->not->toContain($types[0]->id);
    $component->set('status', 'inactive');
    expect($component->get('internshipTypes')->total())->toBe(1)
        ->and($component->get('internshipTypes')->getCollection()->pluck('id'))->toContain($inactive->id);
    $component->set('status', 'active');
    expect($component->get('internshipTypes')->total())->toBe(16)
        ->and($component->get('internshipTypes')->getCollection()->pluck('id'))->not->toContain($inactive->id, $external->id);
    $component->call('clearFilters')->assertSet('status', 'all');
    Livewire::withQueryParams(['status' => 'invalid'])->test('pages::internship-types.index')->assertSet('status', 'all');
});

test('course selection only offers local active courses and the unchanged inactive association on editing', function () {
    $inactive = Course::factory()->for($this->campus)->deactivated()->create(['name' => 'Curso antigo']);
    $external = Course::factory()->create(['name' => 'Curso externo reservado']);
    $component = Livewire::test('internship-types.form-fields');
    expect(array_keys($component->get('courseOptions')))->toBe(['', $this->course->id])
        ->and($component->get('values'))->toMatchArray([
            'course_id' => '', 'max_daily_hours' => 6, 'max_weekly_hours' => 30, 'safety_margin_days' => 7, 'unsatisfactory_value' => 0,
        ]);
    $type = InternshipType::factory()->for($this->course)->create();
    $this->course->update(['deactivated_at' => now()]);
    $edit = Livewire::test('internship-types.form-fields', ['internshipType' => $type]);
    expect($edit->get('courseOptions'))->toBe(['' => 'Selecione um curso', $this->course->id => 'Curso local (curso desativado · associação atual)']);
    expect($edit->get('values.course_id'))->toBe($this->course->id);
    $this->get(route('internship-types.show', $type))->assertSuccessful();
    expect(Livewire::test('internship-types.form-fields')->get('courseOptions'))->not->toHaveKey($this->course->id);
});

test('type pages and selectors reauthorize every livewire request', function (string $componentName, string $change) {
    $type = InternshipType::factory()->for($this->course)->create();
    $parameters = in_array($componentName, ['pages::internship-types.show', 'pages::internship-types.edit', 'internship-types.form-fields'], true)
        ? ['internshipType' => $type] : [];
    $component = Livewire::test($componentName, $parameters);
    if ($change === 'inactive affiliation') {
        $this->office->update(['deactivated_at' => now()]);
    } else {
        $system = Affiliation::factory()->global()->for($this->office->user)->create();
        session()->put('active_affiliation_id', $system->id);
    }
    $component->call('$refresh')->assertForbidden();
})->with(['pages::internship-types.index', 'pages::internship-types.create', 'pages::internship-types.edit', 'pages::internship-types.show', 'internship-types.form-fields'])
    ->with(['inactive affiliation', 'other profile']);

test('type identities and selector campus identities are locked', function (string $componentName, string $property) {
    $type = InternshipType::factory()->for($this->course)->create();
    expect(fn () => Livewire::test($componentName, ['internshipType' => $type])->set($property, 999999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    ['pages::internship-types.show', 'internshipTypeId'],
    ['pages::internship-types.edit', 'internshipTypeId'],
    ['internship-types.form-fields', 'internshipTypeId'],
    ['internship-types.form-fields', 'campusId'],
]);

test('direct mounting cannot expose an external type', function (string $componentName) {
    $type = InternshipType::factory()->create();
    Livewire::test($componentName, ['internshipType' => $type])->assertForbidden();
})->with(['pages::internship-types.show', 'pages::internship-types.edit', 'internship-types.form-fields']);

test('changing selected campus revokes mounted type details edits and selectors', function (string $componentName) {
    $type = InternshipType::factory()->for($this->course)->create();
    $component = Livewire::test($componentName, ['internshipType' => $type]);
    $other = Affiliation::factory()->for($this->office->user)->create(['type' => AffiliationType::InternshipOffice]);
    session()->put('active_affiliation_id', $other->id);
    $component->call('$refresh')->assertForbidden();
})->with(['pages::internship-types.show', 'pages::internship-types.edit', 'internship-types.form-fields']);

test('new type selectors cannot continue after selecting another campus', function () {
    $component = Livewire::test('internship-types.form-fields');
    $other = Affiliation::factory()->for($this->office->user)->create(['type' => AffiliationType::InternshipOffice]);
    session()->put('active_affiliation_id', $other->id);
    $component->call('$refresh')->assertForbidden();
});

test('type lifecycle modals validate passwords clear feedback and complete both transitions', function () {
    $type = InternshipType::factory()->for($this->course)->create();
    $component = Livewire::test('pages::internship-types.show', ['internshipType' => $type])
        ->set('showDeactivation', true)->set('currentPassword', 'wrong')->call('deactivateInternshipType')
        ->assertHasErrors(['currentPassword' => 'current_password'])->assertSet('showDeactivation', true);
    $component->set('showDeactivation', false)->assertSet('currentPassword', '')->assertHasNoErrors();
    $component->set('showDeactivation', true)->set('currentPassword', 'password')->call('deactivateInternshipType')
        ->assertSet('showDeactivation', false)->assertDispatched('toast-show');
    expect($type->fresh()->deactivated_at)->not->toBeNull();
    $component->set('showReactivation', true)->call('reactivateInternshipType')
        ->assertSet('showReactivation', false)->assertDispatched('toast-show');
    expect($type->fresh()->deactivated_at)->toBeNull();
});

test('reactivation is rejected when the course has been deactivated', function () {
    $type = InternshipType::factory()->for($this->course)->deactivated()->create();
    $component = Livewire::test('pages::internship-types.show', ['internshipType' => $type])->set('showReactivation', true);
    $this->course->update(['deactivated_at' => now()]);
    $component->call('reactivateInternshipType')->assertHasErrors('course_id')->assertSet('showReactivation', true);
    expect($type->fresh()->deactivated_at)->not->toBeNull();
});

test('type lifecycle actions recheck changed campus and inactive campus state', function (string $operation, string $change) {
    $type = InternshipType::factory()->for($this->course)->create(['deactivated_at' => $operation === 'reactivateInternshipType' ? now() : null]);
    $component = Livewire::test('pages::internship-types.show', ['internshipType' => $type]);
    if ($change === 'inactive campus') {
        $this->campus->deactivate();
    } else {
        $other = Affiliation::factory()->for($this->office->user)->create(['type' => AffiliationType::InternshipOffice]);
        session()->put('active_affiliation_id', $other->id);
    }
    $component->call($operation)->assertForbidden();
    expect($type->fresh()->deactivated_at === null)->toBe($operation === 'deactivateInternshipType');
})->with(['deactivateInternshipType', 'reactivateInternshipType'])->with(['inactive campus', 'other campus']);

test('campus deactivation immediately freezes mounted type forms and keeps only reading actions', function () {
    $type = InternshipType::factory()->for($this->course)->create();
    $edit = Livewire::test('pages::internship-types.edit', ['internshipType' => $type]);
    $fields = Livewire::test('internship-types.form-fields', ['internshipType' => $type]);
    $this->campus->deactivate();
    $edit->call('$refresh')->assertForbidden();
    $fields->call('$refresh')->assertForbidden();
    $this->get(route('internship-types.show', $type))->assertSuccessful();
});

test('invalid type submissions are rejected without creating a record', function () {
    $data = Arr::except(InternshipType::factory()->for($this->course)->raw(), ['deactivated_at']);
    $this->from(route('internship-types.create'))->post(route('internship-types.store'), [...$data, 'report_weight' => 4])
        ->assertRedirect(route('internship-types.create'))->assertSessionHasErrors('supervisor_evaluation_weight');
    expect(InternshipType::query()->count())->toBe(0);
});

test('course activity is rechecked when submitting a type form opened earlier', function () {
    $component = Livewire::test('internship-types.form-fields');
    $data = Arr::except(InternshipType::factory()->for($this->course)->raw(), ['deactivated_at']);
    $this->course->update(['deactivated_at' => now()]);
    $component->call('$refresh');
    expect($component->get('courseOptions'))->not->toHaveKey($this->course->id);
    $this->post(route('internship-types.store'), $data)->assertSessionHasErrors('course_id');
    expect(InternshipType::query()->count())->toBe(0);
});
