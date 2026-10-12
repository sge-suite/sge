<?php

use App\Enums\AffiliationType;
use App\Enums\BrazilianState;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\City;
use App\Models\Course;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Models\UserPersonalData;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

function campusInterfaceAdministrator(): User
{
    $user = User::factory()->create();
    Affiliation::factory()->global()->for($user)->create();

    return $user;
}

test('campus interface requires authentication', function () {
    $campus = Campus::factory()->create();

    foreach (['index', 'create', 'show', 'edit'] as $page) {
        $this->get(route('campuses.'.$page, in_array($page, ['show', 'edit']) ? $campus : []))
            ->assertRedirect(route('login'));
    }
});

test('system administrator can navigate campus pages without mutating data', function () {
    $user = campusInterfaceAdministrator();
    $campus = Campus::factory()->create(['name' => 'Campus Santa Rosa']);
    $count = Activity::count();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->get(route('campuses.index'))->assertOk();
    $this->get(route('campuses.create'))->assertOk()
        ->assertSee('name="address[city_id]"', false);
    $this->get(route('campuses.show', $campus))->assertOk();
    $this->get(route('campuses.edit', $campus))->assertOk();

    expect(Activity::count())->toBe($count);
});

test('other profiles are forbidden from campus administration routes', function (AffiliationType $type) {
    $user = User::factory()->create();
    $campus = Campus::factory()->create();
    $factory = Affiliation::factory()->for($user)->state(['campus_id' => $campus->id, 'type' => $type]);

    if ($type === AffiliationType::Student) {
        $factory = $factory->student();
    } elseif ($type === AffiliationType::Supervisor) {
        $factory = $factory->supervisor();
    }

    $factory->create();
    $this->actingAs($user);

    foreach (['index', 'create', 'show', 'edit'] as $page) {
        $this->get(route('campuses.'.$page, in_array($page, ['show', 'edit']) ? $campus : []))->assertForbidden();
    }

    $this->get(route('dashboard'))->assertOk();
})->with(array_filter(AffiliationType::cases(), fn (AffiliationType $type): bool => $type !== AffiliationType::SystemAdministrator));

test('only the selected affiliation grants campus frontend access', function () {
    $user = campusInterfaceAdministrator();
    $system = $user->affiliations()->sole();
    $campusAffiliation = Affiliation::factory()->for($user)->create(['type' => AffiliationType::InternshipOffice]);

    $this->actingAs($user)->withSession(['active_affiliation_id' => $campusAffiliation->id]);
    $this->get(route('campuses.index'))->assertForbidden();
    $this->get(route('dashboard'));
    $this->withSession(['active_affiliation_id' => $system->id]);
    $this->get(route('campuses.index'))->assertOk();
});

test('invalid and disabled affiliations cannot open the campus frontend', function () {
    $user = campusInterfaceAdministrator();
    $own = $user->affiliations()->sole();
    $other = Affiliation::factory()->global()->create();

    $this->actingAs($user)->withSession(['active_affiliation_id' => $other->id]);
    $this->get(route('campuses.index'))->assertRedirect(route('affiliations.select'));
    $own->update(['deactivated_at' => now()]);
    $this->withSession(['active_affiliation_id' => $own->id, 'active_affiliation_needs_choice' => false]);
    $this->get(route('campuses.index'))->assertForbidden();
});

test('campus listing searches names and filters availability', function () {
    $this->actingAs(campusInterfaceAdministrator());
    $active = Campus::factory()->create([
        'name' => 'Campus Santa Rosa',
        'cnpj' => '04252011000110',
        'legal_representative_name' => 'Ana Representante',
    ]);
    $inactive = Campus::factory()->deactivated()->create([
        'name' => 'Campus Alegrete',
        'cnpj' => '11222333000181',
        'legal_representative_name' => 'Bruno Representante',
    ]);
    $count = Activity::count();

    $component = Livewire::test('pages::campuses.index')->set('search', 'santa rosa');
    expect($component->instance()->campuses->getCollection()->pluck('id'))->toContain($active->id)
        ->not->toContain($inactive->id);

    $component->set('search', '04.252.011/0001-10');
    expect($component->instance()->campuses->getCollection()->pluck('id'))->not->toContain($active->id);

    $component->set('search', '')->set('status', 'inactive');
    expect($component->instance()->campuses->getCollection()->pluck('id'))->toContain($inactive->id)
        ->not->toContain($active->id);

    $component->set('status', 'active');
    expect($component->instance()->campuses->getCollection()->pluck('id'))->toContain($active->id)
        ->not->toContain($inactive->id);

    $component->set('search', '%')->call('clearFilters')
        ->assertSet('search', '')->assertSet('status', 'all');

    expect(Activity::count())->toBe($count);
});

test('campus filters can be shared by url and pagination resets when filtering', function () {
    $this->actingAs(campusInterfaceAdministrator());
    Campus::factory()->count(16)->create();
    $campus = Campus::factory()->deactivated()->create(['name' => 'Campus Específico']);

    $this->get(route('campuses.index', ['search' => 'Específico', 'status' => 'inactive']))
        ->assertOk();

    $component = Livewire::test('pages::campuses.index')
        ->call('gotoPage', 2)->assertSet('paginators.page', 2)
        ->set('search', 'Específico')->assertSet('paginators.page', 1)

        ->call('gotoPage', 2)->set('status', 'inactive')->assertSet('paginators.page', 1)
        ->call('gotoPage', 999)
        ->call('resetPage');

    expect($component->instance()->campuses->getCollection()->pluck('id'))->toContain($campus->id);

    Livewire::withQueryParams(['status' => 'invalid'])->test('pages::campuses.index')->assertSet('status', 'all');
});

test('city selector loads only cities from the chosen state and resets selection on state change', function () {
    $this->actingAs(campusInterfaceAdministrator());
    $rsCity = City::factory()->create(['name' => 'Santa Rosa', 'state' => BrazilianState::RioGrandeDoSul]);
    $scCity = City::factory()->create(['name' => 'Chapecó', 'state' => BrazilianState::SantaCatarina]);
    $count = Activity::count();

    Livewire::test('cities.select', ['selectedCityId' => $rsCity->id])
        ->assertSet('state', 'RS')->assertSet('cityId', (string) $rsCity->id)

        ->set('state', 'SC')->assertSet('cityId', '')
        ->set('citySearch', 'Chapecó')
        ->set('state', 'invalid');

    expect(Activity::count())->toBe($count);
});

test('city selector handles invalid old input without an exception', function () {
    $this->actingAs(campusInterfaceAdministrator());
    Livewire::test('cities.select', ['selectedCityId' => ['invalid']])
        ->assertSet('state', '')->assertSet('cityId', '');
});

test('city select rejects malformed or overflowing identifiers without querying them', function (string $value) {
    $this->actingAs(campusInterfaceAdministrator());
    Livewire::test('cities.select', ['selectedCityId' => $value])->assertSet('cityId', '')
        ->set('state', 'RS')->set('cityId', $value)->assertSet('cityId', '');
})->with(['invalid', '9223372036854775808', '-1']);

test('searchable city select starts empty searches only after two characters and preserves selection', function () {
    $this->actingAs(campusInterfaceAdministrator());
    City::factory()->count(25)->create(['name' => 'Cidade do Catálogo', 'state' => BrazilianState::RioGrandeDoSul]);
    $selected = City::factory()->create(['name' => 'Z Cidade Selecionada', 'state' => BrazilianState::RioGrandeDoSul]);
    $foreign = City::factory()->create(['state' => BrazilianState::SantaCatarina]);

    $component = Livewire::test('cities.select', ['selectedCityId' => $selected->id]);
    expect($component->instance()->cities)->toBeEmpty();

    $component->set('citySearch', 'C');
    expect($component->instance()->cities)->toBeEmpty();
    $component->set('citySearch', 'Cidade');
    expect($component->instance()->cities)->toHaveCount(20);

    $component->set('citySearch', 'Cidade Selecionada')
        ->set('cityId', (string) $foreign->id)->assertSet('cityId', '')

        ->set('state', 'SC')->assertSet('citySearch', '')->assertSet('cityError', '');
});

test('city select does not query the catalog before the minimum search length', function () {
    $this->actingAs(campusInterfaceAdministrator());
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, 'from "cities"')) {
            $queries[] = $query->sql;
        }
    });

    Livewire::test('cities.select')->set('state', 'RS')
        ->set('citySearch', '')->set('citySearch', 'C')->set('citySearch', ' ')

        ->assertSee('wire:model.live.debounce.500ms="citySearch"', false);

    expect($queries)->toBeEmpty();
});

test('campus registration rejects nested address errors without creating a record', function () {
    $this->actingAs(campusInterfaceAdministrator());
    $city = City::factory()->create(['name' => 'Santa Rosa', 'state' => BrazilianState::RioGrandeDoSul]);

    $this->from(route('campuses.create'))->post(route('campuses.store'), [
        'name' => 'Campus Ainda Não Salvo',
        'cnpj' => '04252011000110',
        'phone' => '(55) 99999-9999',
        'legal_representative_name' => 'Pessoa Responsável',
        'legal_representative_position' => 'Diretor(a) Geral',
        'insurance_company_name' => 'Seguradora Institucional',
        'insurance_policy_number' => 'APOL-1234',
        'address' => ['city_id' => $city->id, 'street' => '', 'number' => '', 'neighborhood' => 'Centro'],
    ])->assertRedirect(route('campuses.create'))->assertSessionHasErrors('address.street');

    $this->withCookie(config('session.cookie'), session()->getId());

    $this->get(route('campuses.create'))->assertOk()

        ->assertSee('name="address[street]"', false);

    expect(Campus::count())->toBe(0);
});

test('successful native campus form redirects to the new campus details', function () {
    $this->actingAs(campusInterfaceAdministrator());
    $city = City::factory()->create();
    $response = $this->post(route('campuses.store'), [
        'name' => 'Novo Campus',
        'cnpj' => '04252011000110',
        'phone' => '(55) 99999-9999',
        'legal_representative_name' => 'Pessoa Responsável',
        'legal_representative_position' => 'Diretor(a) Geral',
        'insurance_company_name' => 'Seguradora Institucional',
        'insurance_policy_number' => 'APOL-1234',
        'citySearch' => 'Santo Augusto',
        'address' => ['city_id' => $city->id, 'street' => 'Rua do Campus', 'number' => 's/n', 'neighborhood' => 'Centro', 'zip_code' => '97015-000'],
    ]);
    $campus = Campus::query()->sole();

    $response->assertRedirect(route('campuses.show', $campus));
    $this->get(route('campuses.show', $campus))->assertOk();
});

test('deactivated campus details remain readable but editing is forbidden', function () {
    $this->actingAs(campusInterfaceAdministrator());
    $campus = Campus::factory()->deactivated()->create();

    $this->get(route('campuses.show', $campus))->assertOk();
    $this->get(route('campuses.edit', $campus))->assertForbidden();
});

test('deactivation password error returns to the open modal without flashing the password', function () {
    $this->actingAs(campusInterfaceAdministrator());
    $campus = Campus::factory()->create();

    $this->from(route('campuses.show', $campus))
        ->patch(route('campuses.deactivate', $campus), ['current_password' => 'senha-incorreta'])
        ->assertRedirect(route('campuses.show', $campus))
        ->assertSessionHasErrors('current_password')->assertSessionMissing('_old_input.current_password');

    $this->withCookie(config('session.cookie'), session()->getId());

    $this->get(route('campuses.show', $campus))->assertOk();
    expect($campus->fresh()->deactivated_at)->toBeNull();
});

test('campus deactivation keeps the modal open after an incorrect password', function () {
    $this->actingAs(campusInterfaceAdministrator());
    $campus = Campus::factory()->create();

    $component = Livewire::test('pages::campuses.show', ['campus' => $campus])
        ->set('showDeactivation', true)
        ->set('currentPassword', 'senha-incorreta')
        ->call('deactivateCampus')
        ->assertHasErrors('currentPassword')
        ->assertSet('showDeactivation', true);

});

test('campus deletion removes unlinked records and rejects campuses with linked records', function () {
    $user = campusInterfaceAdministrator();
    $user->update(['password' => 'password']);
    $this->actingAs($user);

    $unlinkedCampus = Campus::factory()->create();
    $campusWithAffiliation = Campus::factory()->create();
    $campusWithCourse = Campus::factory()->create();
    $campusWithTemplate = Campus::factory()->create();
    $campusWithAddressReference = Campus::factory()->create();

    Affiliation::factory()->create(['campus_id' => $campusWithAffiliation->id]);
    Course::factory()->create(['campus_id' => $campusWithCourse->id]);
    DocumentTemplate::factory()->create(['campus_id' => $campusWithTemplate->id]);
    UserPersonalData::factory()->create(['address_id' => $campusWithAddressReference->address_id]);

    $unlinkedAddressId = $unlinkedCampus->address_id;
    Livewire::test('pages::campuses.show', ['campus' => $unlinkedCampus])
        ->set('showDeletion', true)->set('deletionPassword', 'password')->call('deleteCampus')
        ->assertRedirect(route('campuses.index'));
    $this->assertDatabaseMissing('campuses', ['id' => $unlinkedCampus->id]);
    $this->assertDatabaseMissing('addresses', ['id' => $unlinkedAddressId]);

    foreach ([$campusWithAffiliation, $campusWithCourse, $campusWithTemplate, $campusWithAddressReference] as $campus) {
        Livewire::test('pages::campuses.show', ['campus' => $campus])
            ->set('showDeletion', true)->set('deletionPassword', 'password')->call('deleteCampus')
            ->assertHasErrors('deletion');
        $this->assertModelExists($campus);
    }
});

test('livewire campus deletion rejects linked records added after opening the modal', function () {
    $user = campusInterfaceAdministrator();
    $user->update(['password' => 'senha-correta-para-campus']);
    $this->actingAs($user);
    $campus = Campus::factory()->create();
    $addressId = $campus->address_id;

    $component = Livewire::test('pages::campuses.show', ['campus' => $campus])
        ->set('showDeletion', true)
        ->set('deletionPassword', 'senha-correta-para-campus');

    Affiliation::factory()->create(['campus_id' => $campus->id]);

    $component->call('deleteCampus')
        ->assertSet('showDeletion', true)
        ->assertHasErrors('deletion');

    $this->assertDatabaseHas('campuses', ['id' => $campus->id]);
    $this->assertDatabaseHas('addresses', ['id' => $addressId]);
});

test('livewire campus deletion checks the password and clears it when the modal closes', function () {
    $this->actingAs(campusInterfaceAdministrator());
    $campus = Campus::factory()->create();

    $component = Livewire::test('pages::campuses.show', ['campus' => $campus])
        ->set('showDeletion', true)
        ->set('deletionPassword', 'senha-incorreta')
        ->call('deleteCampus')
        ->assertHasErrors(['deletionPassword' => 'current_password'])
        ->assertSet('showDeletion', true)
        ->assertSet('deletionPassword', 'senha-incorreta');

    $component->call('closeDeletionModal')
        ->assertSet('showDeletion', false)
        ->assertSet('deletionPassword', '')
        ->assertHasNoErrors();

    $this->assertModelExists($campus);
});

test('livewire campus deletion removes the campus and its address after password confirmation', function () {
    $plainPassword = 'senha-correta-para-campus';
    $user = campusInterfaceAdministrator();
    $user->update(['password' => $plainPassword]);
    $this->actingAs($user);
    $campus = Campus::factory()->create();
    $campusId = $campus->id;
    $addressId = $campus->address_id;

    Livewire::test('pages::campuses.show', ['campus' => $campus])
        ->set('showDeletion', true)
        ->set('deletionPassword', $plainPassword)
        ->call('deleteCampus')
        ->assertRedirect(route('campuses.index'));

    $this->assertDatabaseMissing('campuses', ['id' => $campusId]);
    $this->assertDatabaseMissing('addresses', ['id' => $addressId]);
    $this->get(route('campuses.index'))->assertOk();
});

test('livewire deactivation keeps the modal open on a wrong password and clears the field on close', function () {
    $this->actingAs(campusInterfaceAdministrator());
    $campus = Campus::factory()->create();

    $component = Livewire::test('pages::campuses.show', ['campus' => $campus])
        ->set('showDeactivation', true)
        ->set('currentPassword', 'senha-incorreta')
        ->call('deactivateCampus')
        ->assertHasErrors(['currentPassword' => 'current_password'])
        ->assertSet('showDeactivation', true)
        ->assertSet('currentPassword', 'senha-incorreta');

    $component->set('showDeactivation', false)
        ->assertSet('currentPassword', '')
        ->assertHasNoErrors();

    expect($campus->fresh()->deactivated_at)->toBeNull();
});

test('livewire campus deactivation shows a success toast', function () {
    $plainPassword = 'senha-correta-para-campus';
    $user = campusInterfaceAdministrator();
    $user->update(['password' => $plainPassword]);
    $this->actingAs($user);
    $campus = Campus::factory()->create();

    Livewire::test('pages::campuses.show', ['campus' => $campus])
        ->set('showDeactivation', true)
        ->set('currentPassword', $plainPassword)
        ->call('deactivateCampus')
        ->assertSet('showDeactivation', false)
        ->assertSet('currentPassword', '')

        ->assertDispatched('toast-show');

    expect($campus->fresh()->deactivated_at)->not->toBeNull();
});

test('livewire campus pages and city lookup reauthorize each request', function (string $component) {
    $user = campusInterfaceAdministrator();
    $affiliation = $user->affiliations()->sole();
    $campus = Campus::factory()->create();
    $this->actingAs($user);
    $test = Livewire::test($component, in_array($component, ['pages::campuses.show', 'pages::campuses.edit']) ? ['campus' => $campus] : []);
    $affiliation->update(['deactivated_at' => now()]);
    $test->call('$refresh')->assertForbidden();
})->with(['pages::campuses.index', 'pages::campuses.create', 'pages::campuses.edit', 'pages::campuses.show', 'cities.select', 'campuses.form-fields']);

test('livewire campus identity is locked against client tampering', function (string $component) {
    $this->actingAs(campusInterfaceAdministrator());
    $campus = Campus::factory()->create();

    expect(fn () => Livewire::test($component, ['campus' => $campus])->set('campusId', $campus->id + 1))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['pages::campuses.show', 'pages::campuses.edit']);

test('a campus deactivated after opening the edit page is not editable on refresh', function () {
    $this->actingAs(campusInterfaceAdministrator());
    $campus = Campus::factory()->create();
    $component = Livewire::test('pages::campuses.edit', ['campus' => $campus]);
    $campus->deactivate();

    $component->call('$refresh')->assertForbidden();
});

test('switching to a campus affiliation revokes an already mounted administration page', function () {
    $user = campusInterfaceAdministrator();
    $systemAffiliation = $user->affiliations()->sole();
    $campusAffiliation = Affiliation::factory()->for($user)->create(['type' => AffiliationType::InternshipOffice]);
    $this->actingAs($user)->withSession(['active_affiliation_id' => $systemAffiliation->id]);
    $component = Livewire::test('pages::campuses.index');

    session()->put('active_affiliation_id', $campusAffiliation->id);
    $component->call('$refresh')->assertForbidden();
});
