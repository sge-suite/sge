<?php

use App\Enums\BrazilianState;
use App\Models\Affiliation;
use App\Models\City;
use App\Models\User;
use App\Support\BrasilApiCepLookup;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

function cepLookupPayload(): array
{
    return ['cep' => '01234567', 'state' => 'RS', 'city' => 'Santa Rosa', 'street' => 'Rua Nova', 'neighborhood' => 'Centro'];
}

beforeEach(function () {
    Http::preventStrayRequests();
});

test('CEP lookup validates masked and unmasked CEP and sends only digits', function (string $cep) {
    Http::fake(['brasilapi.com.br/api/cep/v1/01234567' => Http::response(cepLookupPayload())]);
    expect(app(BrasilApiCepLookup::class)->lookup($cep))->toMatchArray(['zip_code' => '01234567', 'city_name' => 'Santa Rosa']);
    Http::assertSent(fn ($request) => $request->url() === 'https://brasilapi.com.br/api/cep/v1/01234567' && $request->method() === 'GET');
    Http::assertSentCount(1);
})->with(['01234567', '01234-567', '01.234-567']);

test('invalid CEP never reaches BrasilAPI', function (string $cep) {
    Http::fake();
    expect(fn () => app(BrasilApiCepLookup::class)->lookup($cep))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with(['', 'abc01234567', '0123456', '012345678', '0123456a']);

test('CEP lookup handles missing unavailable and malformed responses', function (string $failure) {
    $response = match ($failure) {
        'missing' => Http::response([], 404),
        'unavailable' => Http::response([], 503),
        'timeout' => Http::failedConnection(),
        'malformed' => Http::response('not json'),
        'different CEP' => Http::response([...cepLookupPayload(), 'cep' => '98780000']),
        'invalid state' => Http::response([...cepLookupPayload(), 'state' => 'XX']),
    };
    Http::fake(['brasilapi.com.br/api/cep/v1/*' => $response]);
    expect(fn () => app(BrasilApiCepLookup::class)->lookup('01234567'))->toThrow(ValidationException::class);
})->with(['missing', 'unavailable', 'timeout', 'malformed', 'different CEP', 'invalid state']);

test('CEP preview applies only after confirmation and preserves the number', function () {
    $user = User::factory()->create();
    Affiliation::factory()->global()->for($user)->create();
    $this->actingAs($user);
    $city = City::factory()->create(['name' => 'Sánta Rósa', 'state' => BrazilianState::RioGrandeDoSul]);
    Http::fake(['brasilapi.com.br/api/cep/v1/*' => Http::response(cepLookupPayload())]);

    $component = Livewire::test('campuses.form-fields')->set('values.address.zip_code', '01234-567')
        ->set('values.address.street', 'Rua Antiga')->set('values.address.number', 's/n')
        ->call('lookupCep')->assertSet('showCepLookupPreview', true)

        ->assertSet('values.address.street', 'Rua Antiga')
        ->assertSet('pendingCepLookup.city_id', $city->id)->call('cancelCepLookup')
        ->assertSet('showCepLookupPreview', false)->assertSet('values.address.street', 'Rua Antiga');
    $component->call('lookupCep')->call('applyCepLookup')->assertSet('values.address.street', 'Rua Nova')
        ->assertSet('values.address.neighborhood', 'Centro')->assertSet('values.address.number', 's/n')
        ->assertSet('selectedCityId', $city->id)->assertSet('showCepLookupPreview', false);
});

test('CEP preview rejects a changed CEP and preserves existing fields on failure', function () {
    $user = User::factory()->create();
    Affiliation::factory()->global()->for($user)->create();
    $this->actingAs($user);
    Http::fake(['brasilapi.com.br/api/cep/v1/*' => Http::response(cepLookupPayload())]);
    Livewire::test('campuses.form-fields')->set('values.address.zip_code', '01234567')
        ->set('values.address.street', 'Rua Antiga')->call('lookupCep')->set('values.address.zip_code', '98780000')
        ->call('applyCepLookup')->assertHasErrors('values.address.zip_code')->assertSet('values.address.street', 'Rua Antiga');
    Http::fake(['brasilapi.com.br/api/cep/v1/*' => Http::failedConnection()]);
    Livewire::test('campuses.form-fields')->set('values.address.zip_code', '01234567')
        ->set('values.address.street', 'Rua Antiga')->call('lookupCep')->assertHasErrors('values.address.zip_code')
        ->assertSet('values.address.street', 'Rua Antiga')->assertSet('showCepLookupPreview', false);
});

test('CEP lookup preserves empty response fields and clears unmatched or ambiguous city', function (bool $ambiguous) {
    $user = User::factory()->create();
    Affiliation::factory()->global()->for($user)->create();
    $this->actingAs($user);
    $city = City::factory()->create(['name' => 'Outra cidade', 'state' => BrazilianState::RioGrandeDoSul]);
    if ($ambiguous) {
        City::factory()->create(['name' => 'Santa Rosa', 'state' => BrazilianState::RioGrandeDoSul]);
        City::factory()->create(['name' => 'Sánta Rósa', 'state' => BrazilianState::RioGrandeDoSul]);
    }
    Http::fake(['brasilapi.com.br/api/cep/v1/*' => Http::response([...cepLookupPayload(), 'street' => '', 'neighborhood' => ''])]);
    Livewire::test('campuses.form-fields')->set('values.address.zip_code', '01234567')->set('selectedCityId', $city->id)
        ->set('values.address.street', 'Rua Antiga')->set('values.address.neighborhood', 'Bairro Antigo')
        ->call('lookupCep')->call('applyCepLookup')->assertSet('selectedCityId', null)
        ->assertSet('values.address.street', 'Rua Antiga')->assertSet('values.address.neighborhood', 'Bairro Antigo');
})->with([false, true]);

test('CEP lookup rejects users without campus editing permission', function () {
    Http::fake();
    $this->actingAs(User::factory()->create());
    Livewire::test('campuses.form-fields')->assertForbidden();
    Http::assertNothingSent();
});

test('CEP proposals are locked and authorization is rechecked before applying', function () {
    $user = User::factory()->create();
    $affiliation = Affiliation::factory()->global()->for($user)->create();
    $this->actingAs($user);
    Http::fake(['brasilapi.com.br/api/cep/v1/*' => Http::response(cepLookupPayload())]);
    $component = Livewire::test('campuses.form-fields')->set('values.address.zip_code', '01234567')->call('lookupCep');
    expect(fn () => $component->set('pendingCepLookup.street', 'Manipulado'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    $affiliation->update(['deactivated_at' => now()]);
    $component->call('applyCepLookup')->assertForbidden();
});

test('invalid CEP in the form does not trigger a request', function () {
    $user = User::factory()->create();
    Affiliation::factory()->global()->for($user)->create();
    $this->actingAs($user);
    Http::fake();
    Livewire::test('campuses.form-fields')->set('values.address.zip_code', 'a01234567')->call('lookupCep')
        ->assertHasErrors('values.address.zip_code')->assertSet('showCepLookupPreview', false);
    Http::assertNothingSent();
});

test('shared address preview explains when CEP lookup has no changes', function (string $componentName) {
    $user = User::factory()->create();
    if ($componentName === 'campuses.form-fields') {
        $affiliation = Affiliation::factory()->global()->for($user)->create();
    } else {
        $affiliation = Affiliation::factory()->server()->for($user)->create();
    }
    $this->actingAs($user)->withSession(['active_affiliation_id' => $affiliation->id]);
    $city = City::factory()->create(['name' => 'Santa Rosa', 'state' => BrazilianState::RioGrandeDoSul]);
    Http::fake(['*cep/v1/*' => Http::response(cepLookupPayload())]);
    $component = Livewire::test($componentName)->set('values.address.zip_code', '01234-567')
        ->set('values.address.street', 'Rua Nova')->set('values.address.neighborhood', 'Centro')
        ->set('values.address.number', '123')->set('selectedCityId', $city->id)
        ->call('lookupCep')->assertSet('showCepLookupPreview', true)
        ->assertSee('Os dados encontrados já correspondem ao formulário. Nenhum campo será alterado.');
    expect($component->instance()->cepLookupPreviewChanges())->toBe([]);
    $component->call('cancelCepLookup')->assertSet('showCepLookupPreview', false)
        ->assertSet('values.address.number', '123')->assertSet('values.address.street', 'Rua Nova');
})->with(['campuses.form-fields']);

test('shared address preview exposes independent proposed field changes', function (string $componentName) {
    $user = User::factory()->create();
    $affiliation = $componentName === 'campuses.form-fields'
        ? Affiliation::factory()->global()->for($user)->create()
        : Affiliation::factory()->server()->for($user)->create();
    $this->actingAs($user)->withSession(['active_affiliation_id' => $affiliation->id]);
    $city = City::factory()->create(['name' => 'Santa Rosa', 'state' => BrazilianState::RioGrandeDoSul]);
    Http::fake(['*cep/v1/*' => Http::response(cepLookupPayload())]);
    $component = Livewire::test($componentName)->set('values.address.zip_code', '01234567')
        ->set('values.address.street', 'Rua Antiga')->set('values.address.neighborhood', 'Bairro Antigo')
        ->set('values.address.number', '123')->call('lookupCep');
    $changes = collect($component->instance()->cepLookupPreviewChanges());
    expect($changes->pluck('label')->all())->toBe(['Logradouro', 'Bairro', 'Cidade'])
        ->and($changes->first()['current'])->toBe('Rua Antiga')->and($changes->first()['proposed'])->toBe('Rua Nova');
    $component->call('applyCepLookup')->assertSet('values.address.street', 'Rua Nova')
        ->assertSet('values.address.neighborhood', 'Centro')->assertSet('values.address.number', '123')
        ->assertSet('selectedCityId', $city->id)->assertSet('showCepLookupPreview', false);
})->with(['campuses.form-fields']);
