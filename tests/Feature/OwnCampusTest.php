<?php

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\City;
use App\Models\UserPersonalData;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->campus = Campus::factory()->create(['cnpj' => '11222333000181']);
    $this->administrator = Affiliation::factory()->for($this->campus)->create(['type' => AffiliationType::CampusAdministrator]);
    $this->actingAs($this->administrator->user)->withSession(['active_affiliation_id' => $this->administrator->id]);
});

test('own campus page exposes only local editable fields and selected campus navigation', function () {
    $this->get(route('dashboard'))->assertOk()->assertSee(route('campuses.own'));
    $this->get(route('campuses.own'))->assertOk()->assertSee($this->campus->name)
        ->assertSee('name="phone"', false)->assertSee('name="legal_representative_name"', false)
        ->assertSee('name="legal_representative_position"', false)->assertSee('name="insurance_company_name"', false)
        ->assertSee('name="insurance_policy_number"', false)->assertSee('Salvar alterações')
        ->assertSee('name="name"', false)->assertSee('name="cnpj"', false)
        ->assertSee('name="address[street]"', false)->assertSee('BrasilAPI')
        ->assertDontSee('Desativar campus')->assertDontSee('Apagar campus')
        ->assertSeeInOrder(['Meu campus', 'Salvar alterações', 'Dados do campus']);
});

test('own campus changes return to the page and audit the selected affiliation', function () {
    $city = City::factory()->create();
    $this->from(route('campuses.own'))->put(route('campuses.update', $this->campus), [
        'name' => 'Campus atualizado',
        'cnpj' => '04.252.011/0001-10',
        'address' => ['city_id' => $city->id, 'street' => 'Rua nova', 'number' => '10', 'neighborhood' => 'Centro', 'zip_code' => '98780-000'],
        'phone' => '(55) 98888-7777',
        'legal_representative_name' => 'Responsável local',
        'legal_representative_position' => 'Direção',
        'insurance_company_name' => 'Seguradora local',
        'insurance_policy_number' => 'APOL-001',
    ])->assertSessionHasNoErrors()->assertRedirect(route('campuses.own'));
    expect($this->campus->fresh()->cnpj)->toBe('04252011000110')
        ->and($this->campus->fresh()->name)->toBe('Campus atualizado')
        ->and($this->campus->fresh()->address->street)->toBe('Rua nova')
        ->and($this->campus->fresh()->address->city_id)->toBe($city->id)
        ->and(Activity::forSubject($this->campus->address)->where('event', 'updated')->sole()->causer->is($this->administrator))->toBeTrue()
        ->and($this->campus->fresh()->phone)->toBe('55988887777')
        ->and($this->campus->fresh()->legal_representative_name)->toBe('Responsável local')
        ->and(Activity::forSubject($this->campus)->where('event', 'updated')->sole()->causer->is($this->administrator))->toBeTrue();
});

test('own campus cannot alter protected fields or another campus', function () {
    foreach (['address_id' => 999, 'deactivated_at' => now()->toDateTimeString(), 'campus_id' => 999] as $field => $value) {
        $this->put(route('campuses.update', $this->campus), [$field => $value])->assertSessionHasErrors($field);
    }
    $other = Campus::factory()->create();
    $this->put(route('campuses.update', $other), ['phone' => '(55) 98888-7777'])->assertForbidden();
    Livewire::test('campuses.form-fields', ['campus' => $other])->assertForbidden();
    $component = Livewire::test('pages::campuses.own');
    expect(fn () => $component->set('campusId', $other->id))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('local campus form validates institutional lookup actions', function (string $action) {
    Livewire::test('campuses.form-fields', ['campus' => $this->campus])->set('values.cnpj', '123')->call($action)->assertHasErrors('values.cnpj');
})->with(['lookupCnpj', 'applyCompanyLookup']);

test('own campus components revalidate a changed or deactivated affiliation', function (string $componentName, string $change) {
    $parameters = $componentName === 'campuses.form-fields' ? ['campus' => $this->campus] : [];
    $component = Livewire::test($componentName, $parameters);
    if ($change === 'deactivated') {
        $this->administrator->update(['deactivated_at' => now()]);
    } else {
        $other = Affiliation::factory()->for($this->administrator->user)->create(['type' => AffiliationType::CampusAdministrator]);
        session()->put('active_affiliation_id', $other->id);
    }
    $component->call('$refresh')->assertForbidden();
})->with(['pages::campuses.own', 'campuses.form-fields'])->with(['deactivated', 'other campus']);

test('another selected profile does not grant own campus access or navigation', function () {
    $other = Affiliation::factory()->global()->for($this->administrator->user)->create();
    $this->withSession(['active_affiliation_id' => $other->id])->get(route('campuses.own'))->assertForbidden();
    $this->get(route('dashboard'))->assertDontSee('href="'.route('campuses.own').'"', false);
});

test('inactive own campus is readable and mounted editable fields are revoked', function () {
    $component = Livewire::test('campuses.form-fields', ['campus' => $this->campus]);
    $this->campus->deactivate();
    $component->call('$refresh')->assertForbidden();
    $this->get(route('campuses.own'))->assertOk()->assertSee('Campus somente para leitura')
        ->assertSee($this->campus->legal_representative_name)->assertDontSee('Salvar alterações')->assertDontSee('name="phone"', false);
    $this->put(route('campuses.update', $this->campus), ['phone' => '(55) 98888-7777'])->assertForbidden();
});

test('own campus form preserves allowed input after a validation error', function () {
    $this->from(route('campuses.own'))->put(route('campuses.update', $this->campus), ['phone' => '', 'legal_representative_name' => 'Nome preenchido'])
        ->assertRedirect(route('campuses.own'))->assertSessionHasErrors('phone');
    $this->withCookie(config('session.cookie'), session()->getId());
    $this->get(route('campuses.own'))->assertOk()->assertSee('value="Nome preenchido"', false)->assertSee('Informe o telefone do campus.');
});

test('own campus address validation preserves historical references', function () {
    $address = $this->campus->address;
    $input = ['city_id' => $address->city_id, 'street' => 'Outra rua', 'number' => '10', 'neighborhood' => 'Centro'];
    $this->put(route('campuses.update', $this->campus), ['address' => [...$input, 'city_id' => 99999999]])
        ->assertSessionHasErrors('address.city_id');
    UserPersonalData::factory()->create(['address_id' => $address->id]);
    $this->put(route('campuses.update', $this->campus), ['name' => 'Nome não gravado', 'address' => $input])
        ->assertSessionHasErrors('address');
    expect($address->fresh()->street)->toBe($address->street)
        ->and($this->campus->fresh()->name)->toBe($this->campus->name);
});

test('own campus city selector revalidates scope and active campus', function () {
    $city = $this->campus->address->city;
    $selector = Livewire::test('cities.select', ['selectedCityId' => $city->id, 'campusId' => $this->campus->id])
        ->assertSet('cityId', (string) $city->id);
    $other = Campus::factory()->create();
    Livewire::test('cities.select', ['campusId' => $other->id])->assertForbidden();
    $this->campus->deactivate();
    $selector->call('$refresh')->assertForbidden();
});

test('an invalid cnpj rejects the entire local campus update', function () {
    $this->putJson(route('campuses.update', $this->campus), [
        'name' => 'Nome permitido com CNPJ inválido',
        'cnpj' => '11111111111111',
    ])->assertUnprocessable()->assertJsonValidationErrors('cnpj');

    expect($this->campus->fresh()->cnpj)->toBe($this->campus->cnpj)
        ->and($this->campus->fresh()->name)->toBe($this->campus->name)
        ->and(Activity::forSubject($this->campus)->where('event', 'updated')->exists())->toBeFalse();
});

test('local cnpj changes preserve uniqueness and selected campus scope', function () {
    $other = Campus::factory()->create(['cnpj' => '04252011000110']);
    $this->putJson(route('campuses.update', $this->campus), ['cnpj' => $other->cnpj])
        ->assertUnprocessable()->assertJsonValidationErrors('cnpj');
    $this->putJson(route('campuses.update', $other), ['cnpj' => '11222333000181'])
        ->assertForbidden();
    $this->administrator->update(['deactivated_at' => now()]);
    $this->putJson(route('campuses.update', $this->campus), ['cnpj' => '11222333000181'])
        ->assertForbidden();
    expect($this->campus->fresh()->cnpj)->toBe($this->campus->cnpj)
        ->and($other->fresh()->cnpj)->toBe($other->cnpj);
});
