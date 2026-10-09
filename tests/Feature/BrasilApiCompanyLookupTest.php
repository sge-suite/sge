<?php

use App\Enums\BrazilianState;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\City;
use App\Models\User;
use App\Support\BrasilApiCompanyLookup;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

function companyLookupPayload(): array
{
    return [
        'cnpj' => '04252011000110',
        'razao_social' => 'Instituto de Ensino',
        'nome_fantasia' => 'Instituto Federal Farroupilha',
        'ddd_telefone_1' => '5533334444',
        'email' => 'campus@example.test',
        'descricao_tipo_de_logradouro' => 'RUA',
        'logradouro' => 'DAS FLORES',
        'numero' => '123',
        'bairro' => 'Centro',
        'cep' => '98780000',
        'uf' => 'RS',
        'municipio' => 'Santa Rosa',
        'codigo_municipio_ibge' => 4317202,
        'qsa' => [['nome_socio' => 'Não é o representante do campus']],
    ];
}

function companyLookupAdministrator(): User
{
    $user = User::factory()->create();
    Affiliation::factory()->global()->for($user)->create();

    return $user;
}

beforeEach(function () {
    Http::preventStrayRequests();
});

test('company lookup normalizes cnpj and maps the legal trade and address names', function () {
    Http::fake(['brasilapi.com.br/api/cnpj/v1/04252011000110' => Http::response(companyLookupPayload())]);
    $data = app(BrasilApiCompanyLookup::class)->lookup('04.252.011/0001-10');

    expect($data)->toBe([
        'legal_name' => 'Instituto de Ensino',
        'trade_name' => 'Instituto Federal Farroupilha',
        'phone' => '5533334444',
        'address' => ['street' => 'RUA DAS FLORES', 'number' => '123', 'neighborhood' => 'Centro', 'zip_code' => '98780000', 'ibge_code' => '4317202', 'city_name' => 'Santa Rosa', 'state' => 'RS'],
    ]);
    Http::assertSent(fn ($request) => $request->url() === 'https://brasilapi.com.br/api/cnpj/v1/04252011000110' && $request->method() === 'GET');
    Http::assertSentCount(1);
});

test('invalid cnpj never reaches the external service', function (string $cnpj) {
    Http::fake();
    expect(fn () => app(BrasilApiCompanyLookup::class)->lookup($cnpj))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with(['', '123', '00000000000000', '04252011000111']);

test('company lookup degrades gracefully on missing unavailable or malformed responses', function (string $failure) {
    $response = match ($failure) {
        'missing' => Http::response([], 404),
        'unavailable' => Http::response([], 503),
        'throttled' => Http::response([], 429),
        'timeout' => Http::failedConnection(),
        'malformed' => Http::response('not json'),
        'different company' => Http::response([...companyLookupPayload(), 'cnpj' => '11222333000181']),
    };
    Http::fake(['brasilapi.com.br/api/cnpj/v1/*' => $response]);
    expect(fn () => app(BrasilApiCompanyLookup::class)->lookup('04252011000110'))->toThrow(ValidationException::class);
})->with(['missing', 'unavailable', 'throttled', 'timeout', 'malformed', 'different company']);

test('campus lookup previews both names and applies data only after confirmation', function () {
    $this->actingAs(companyLookupAdministrator());
    $city = City::factory()->create(['ibge_code' => '4317202', 'name' => 'Santa Rosa', 'state' => BrazilianState::RioGrandeDoSul]);
    Http::fake(['brasilapi.com.br/api/cnpj/v1/*' => Http::response(companyLookupPayload())]);
    $count = Activity::count();

    $component = Livewire::test('campuses.form-fields')
        ->set('values.cnpj', '04.252.011/0001-10')
        ->set('values.name', 'Nome digitado manualmente')
        ->set('values.phone', '(55) 1111-2222')
        ->set('values.address.street', 'Rua digitada manualmente')
        ->set('values.legal_representative_name', 'Representante Informado')
        ->set('values.insurance_policy_number', 'AP-123')
        ->call('lookupCnpj')->assertHasNoErrors()
        ->assertSet('showLookupPreview', true)
        ->assertSet('values.name', 'Nome digitado manualmente')
        ->assertSet('values.phone', '(55) 1111-2222')
        ->assertSet('values.address.street', 'Rua digitada manualmente')
        ->assertSet('selectedCityId', null)->assertSet('citySelectionVersion', 0)
        ->assertSee('Razão social')->assertSee('Nome fantasia')
        ->assertSee('Nome digitado manualmente')->assertSee('Instituto de Ensino')
        ->assertSee('Rua digitada manualmente')->assertSee('RUA DAS FLORES')
        ->set('selectedCompanyName', 'trade_name')
        ->assertSet('values.name', 'Nome digitado manualmente')
        ->assertSee('Instituto Federal Farroupilha')
        ->call('applyCompanyLookup')->assertHasNoErrors()
        ->assertSet('showLookupPreview', false)
        ->assertSet('values.name', 'Instituto Federal Farroupilha')
        ->assertSet('values.phone', '(55) 3333-4444')
        ->assertSet('values.address.street', 'RUA DAS FLORES')
        ->assertSet('values.address.number', '123')->assertSet('values.address.neighborhood', 'Centro')
        ->assertSet('values.address.zip_code', '98780-000')
        ->assertSet('selectedCityId', $city->id)->assertSet('citySelectionVersion', 1)
        ->assertSet('values.legal_representative_name', 'Representante Informado')
        ->assertSet('values.insurance_policy_number', 'AP-123')
        ->assertSee('Santa Rosa')->assertSee('name="address[city_id]"', false)
        ->assertSee('value="RUA DAS FLORES"', false)
        ->assertDispatched('toast-show', fn (string $event, array $parameters): bool => ($parameters['slots']['text'] ?? null) === 'Dados aplicados ao formulário. Confira as informações antes de salvar.');

    expect(Campus::count())->toBe(0)->and(Activity::count())->toBe($count);
});

test('unavailable company lookup preserves manual values and allows retry', function () {
    $this->actingAs(companyLookupAdministrator());
    Http::fakeSequence()->push([], 503)->push(companyLookupPayload());

    Livewire::test('campuses.form-fields')->set('values.cnpj', '04252011000110')
        ->set('values.name', 'Nome manual')->set('values.address.street', 'Rua manual')
        ->call('lookupCnpj')->assertHasErrors('values.cnpj')
        ->assertSet('values.name', 'Nome manual')->assertSet('values.address.street', 'Rua manual')
        ->assertSet('citySelectionVersion', 0)
        ->call('lookupCnpj')->assertHasNoErrors()->assertSet('values.name', 'Nome manual')
        ->assertSet('showLookupPreview', true)
        ->call('applyCompanyLookup')->assertSet('values.name', 'Instituto de Ensino');
});

test('cancelling the lookup preview leaves the form untouched', function () {
    $this->actingAs(companyLookupAdministrator());
    Http::fake(['brasilapi.com.br/api/cnpj/v1/*' => Http::response(companyLookupPayload())]);

    Livewire::test('campuses.form-fields')
        ->set('values.cnpj', '04252011000110')->set('values.name', 'Nome informado')
        ->call('lookupCnpj')
        ->assertSet('showLookupPreview', true)
        ->call('cancelCompanyLookup')
        ->assertSet('showLookupPreview', false)->assertSet('pendingCompanyLookup', [])
        ->assertSet('values.name', 'Nome informado');
});

test('invalid cnpj shows a field error and keeps the form values', function () {
    $this->actingAs(companyLookupAdministrator());
    Http::fake();
    Livewire::test('campuses.form-fields')->set('values.cnpj', '123')
        ->set('values.name', 'Nome manual')->call('lookupCnpj')
        ->assertHasErrors('values.cnpj')->assertSet('values.name', 'Nome manual');
    Http::assertNothingSent();
});

test('confirmation preserves the manual phone when the api omits it and clears an unresolved city', function () {
    $this->actingAs(companyLookupAdministrator());
    $campus = Campus::factory()->create();
    City::factory()->create(['ibge_code' => '4317202', 'state' => BrazilianState::SantaCatarina]);
    Http::fake(['brasilapi.com.br/api/cnpj/v1/*' => Http::response([...companyLookupPayload(), 'ddd_telefone_1' => '', 'ddd_telefone_2' => null])]);

    Livewire::test('campuses.form-fields', ['campus' => $campus])
        ->set('values.cnpj', '04252011000110')
        ->set('values.phone', '55999999999')->call('lookupCnpj')
        ->assertHasNoErrors()->assertSet('values.phone', '55999999999')
        ->assertSet('selectedCityId', $campus->address->city_id)
        ->call('applyCompanyLookup')
        ->assertSet('values.phone', '55999999999')
        ->assertSet('selectedCityId', null)->assertSee('Cidade não encontrada no catálogo. Selecione a UF e busque a cidade.');
    expect($campus->fresh()->name)->toBe($campus->name);
});

test('company lookup reauthorizes selected affiliation and campus state', function (string $change) {
    $user = companyLookupAdministrator();
    $this->actingAs($user);
    $campus = Campus::factory()->create();
    Http::fake();
    $component = Livewire::test('campuses.form-fields', ['campus' => $campus]);

    if ($change === 'campus') {
        $campus->deactivate();
    } else {
        $user->affiliations()->sole()->update(['deactivated_at' => now()]);
    }

    $component->set('values.cnpj', '04252011000110')->assertForbidden();
    Http::assertNothingSent();
})->with(['campus', 'affiliation']);

test('company lookup campus identity cannot be changed by the client', function () {
    $this->actingAs(companyLookupAdministrator());
    expect(fn () => Livewire::test('campuses.form-fields')->set('campusId', 999))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('company lookup response is locked and a stale cnpj cannot apply its preview', function () {
    $this->actingAs(companyLookupAdministrator());
    Http::fake(['brasilapi.com.br/api/cnpj/v1/*' => Http::response(companyLookupPayload())]);
    $component = Livewire::test('campuses.form-fields')->set('values.cnpj', '04252011000110')
        ->set('values.name', 'Nome manual')->call('lookupCnpj');

    expect(fn () => $component->set('pendingCompanyLookup.legal_name', 'Nome adulterado'))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    $component->set('values.cnpj', '11222333000181')->call('applyCompanyLookup')
        ->assertHasErrors('values.cnpj')->assertSet('values.name', 'Nome manual')
        ->assertSet('selectedCityId', null);
});
