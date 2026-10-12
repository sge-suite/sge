<?php

use App\Enums\AffiliationType;
use App\Enums\BrazilianState;
use App\Models\Address;
use App\Models\Affiliation;
use App\Models\City;
use App\Models\GrantingParty;
use App\Models\GrantingPartyRegistrationRequest;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->office = Affiliation::factory()->server()->create();
    $this->actingAs($this->office->user)->withSession(['active_affiliation_id' => $this->office->id]);
    $this->party = GrantingParty::factory()->for($this->office->campus)->create();
});

test('catalog pages and field component render for the office', function () {
    foreach (['index', 'create', 'show', 'edit'] as $page) {
        $this->get(route('granting-parties.'.$page, in_array($page, ['show', 'edit']) ? $this->party : []))->assertSuccessful();
    }
    Livewire::test('granting-parties.form-fields', ['grantingParty' => $this->party])->assertSet('campusId', $this->office->campus_id);
});

test('list isolates campus paginates searches and filters', function () {
    GrantingParty::factory()->for($this->office->campus)->count(16)->create();
    $inactive = GrantingParty::factory()->for($this->office->campus)->deactivated()->create(['name' => 'Unidade histórica']);
    GrantingParty::factory()->create(['name' => 'Outra unidade']);
    $component = Livewire::test('pages::granting-parties.index');
    expect($component->get('grantingParties')->total())->toBe(18)->and($component->get('grantingParties')->count())->toBe(15);
    $component->set('status', 'inactive');
    expect($component->get('grantingParties')->pluck('id')->all())->toBe([$inactive->id]);
    $component->set('status', 'all')->set('search', 'histórica');
    expect($component->get('grantingParties')->total())->toBe(1);
    $component->set('search', 'Outra unidade');
    expect($component->get('grantingParties')->total())->toBe(0);
    $component->set('search', '04.252.011/0001-10');
    expect($component->get('grantingParties')->total())->toBe(18);
});

test('list searches cities by selected UF and filters by the selected city within campus scope', function () {
    $rsSantaRosa = City::factory()->create(['name' => 'Santa Rosa', 'state' => BrazilianState::RioGrandeDoSul]);
    $rsIjui = City::factory()->create(['name' => 'Ijuí', 'state' => BrazilianState::RioGrandeDoSul]);
    $scSantaRosa = City::factory()->create(['name' => 'Santa Rosa', 'state' => BrazilianState::SantaCatarina]);
    $this->party->address->update(['city_id' => $rsIjui->id]);

    $rsParty = GrantingParty::factory()->for($this->office->campus)
        ->for(Address::factory()->for($rsSantaRosa), 'address')->create();
    $scParty = GrantingParty::factory()->for($this->office->campus)
        ->for(Address::factory()->for($scSantaRosa), 'address')->create();
    GrantingParty::factory()->for(Address::factory()->for($rsSantaRosa), 'address')->create();

    $allStates = Livewire::test('pages::granting-parties.index')->set('citySearch', 'Santa');
    expect($allStates->instance()->cities->pluck('id')->all())->toContain($rsSantaRosa->id, $scSantaRosa->id);
    $allStates->set('cityId', (string) $scSantaRosa->id)->assertSet('state', 'all');
    expect($allStates->get('grantingParties')->pluck('id')->all())->toBe([$scParty->id]);
    $allStates->call('clearFilters')->assertSet('cityId', '')->assertSet('citySearch', '');

    $component = Livewire::test('pages::granting-parties.index')
        ->call('gotoPage', 2)
        ->set('state', 'RS')->assertSet('paginators.page', 1)
        ->set('citySearch', 'S');
    expect($component->instance()->cities)->toBeEmpty();

    $component->set('citySearch', 'Santa');
    expect($component->instance()->cities->pluck('id')->all())->toContain($rsSantaRosa->id)
        ->not->toContain($scSantaRosa->id);
    $component->set('cityId', (string) $rsSantaRosa->id);
    expect($component->get('grantingParties')->pluck('id')->all())->toBe([$rsParty->id]);

    $component->set('state', 'SC')->assertSet('cityId', '')->set('citySearch', 'Santa');
    expect($component->instance()->cities->pluck('id')->all())->toContain($scSantaRosa->id)
        ->not->toContain($rsSantaRosa->id);
    $component->set('cityId', (string) $scSantaRosa->id);
    expect($component->get('grantingParties')->pluck('id')->all())->toBe([$scParty->id]);

    $fromUrl = Livewire::withQueryParams(['state' => 'RS', 'city' => (string) $rsSantaRosa->id])->test('pages::granting-parties.index');
    expect($fromUrl->get('grantingParties')->pluck('id')->all())->toBe([$rsParty->id]);

    $component->set('state', 'RS')->set('cityId', (string) $scSantaRosa->id)->assertSet('cityId', '');
    expect($component->get('grantingParties')->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->party->id, $rsParty->id])->sort()->values()->all());

    $component->set('state', 'invalid')->assertSet('state', 'all')->assertSet('cityId', '')
        ->call('clearFilters')->assertSet('state', 'all')->assertSet('cityId', '')->assertSet('citySearch', '');
    Livewire::withQueryParams(['state' => 'invalid'])->test('pages::granting-parties.index')->assertSet('state', 'all');
});

test('mounted pages and fields reauthorize context and inactive campus', function (string $componentName, string $change) {
    $component = Livewire::test($componentName, ['grantingParty' => $this->party]);
    if ($change === 'campus') {
        $this->office->campus->deactivate();
    } elseif ($change === 'affiliation') {
        $this->office->update(['deactivated_at' => now()]);
    } else {
        $other = Affiliation::factory()->for($this->office->user)->create(['type' => AffiliationType::InternshipOffice]);
        session()->put('active_affiliation_id', $other->id);
    }
    $component->call('$refresh')->assertForbidden();
})->with(['pages::granting-parties.edit', 'granting-parties.form-fields'])->with(['campus', 'affiliation', 'switch']);

test('identities are locked and foreign mounting is denied', function () {
    expect(fn () => Livewire::test('pages::granting-parties.show', ['grantingParty' => $this->party])->set('grantingPartyId', 999))->toThrow(CannotUpdateLockedPropertyException::class);
    $foreign = GrantingParty::factory()->create();
    Livewire::test('pages::granting-parties.show', ['grantingParty' => $foreign])->assertForbidden();
    Livewire::test('granting-parties.form-fields', ['grantingParty' => $foreign])->assertForbidden();
});

test('modals clear passwords and execute transitions and physical deletion', function () {
    $component = Livewire::test('pages::granting-parties.show', ['grantingParty' => $this->party]);
    $component->set('showDeactivation', true)->set('currentPassword', 'bad')->call('deactivateGrantingParty')->assertHasErrors('currentPassword');
    $component->set('showDeactivation', false)->assertSet('currentPassword', '');
    $component->set('currentPassword', 'password')->call('deactivateGrantingParty')->assertHasNoErrors();
    expect($this->party->fresh()->deactivated_at)->not->toBeNull();
    $component->call('reactivateGrantingParty');
    expect($this->party->fresh()->deactivated_at)->toBeNull();
    $component->set('deletionPassword', 'password')->call('deleteGrantingParty')->assertRedirect(route('granting-parties.index'));
    $this->assertModelMissing($this->party);
    expect(Address::query()->whereKey($this->party->address_id)->exists())->toBeFalse();
    $this->get(route('granting-parties.show', $this->party->id))->assertNotFound();
});

test('deletion rechecks references added after opening its modal', function () {
    $component = Livewire::test('pages::granting-parties.show', ['grantingParty' => $this->party])->set('showDeletion', true);
    GrantingPartyRegistrationRequest::factory()->for($this->office->campus)->create(['granting_party_id' => $this->party->id]);
    $component->set('deletionPassword', 'password')->call('deleteGrantingParty')->assertForbidden();
    expect($this->party->fresh()->exists)->toBeTrue();
});

test('CEP consultation previews applies cancels and invalidates changed CEP without saving', function () {
    Http::fake(['*cep/v1/*' => Http::response(['cep' => '01234567', 'street' => 'Rua consultada', 'neighborhood' => 'Centro', 'city' => 'Cidade desconhecida', 'state' => 'RS'])]);
    $component = Livewire::test('granting-parties.form-fields', ['grantingParty' => $this->party]);
    $component->set('values.address.zip_code', '01234-567')->call('lookupCep')->assertSet('showCepLookupPreview', true);
    expect($this->party->address->street)->not->toBe('Rua consultada');
    $component->call('cancelCepLookup')->assertSet('pendingCepLookup', []);
    $component->call('lookupCep')->call('applyCepLookup')->assertSet('values.address.street', 'Rua consultada')->assertSet('selectedCityId', null);
    $component->call('lookupCep')->set('values.address.zip_code', '99999-999')->call('applyCepLookup')->assertHasErrors('values.address.zip_code');
});

test('CEP failure preserves values and manual entry remains available', function () {
    Http::fake(['*' => Http::response([], 503)]);
    $component = Livewire::test('granting-parties.form-fields', ['grantingParty' => $this->party]);
    $street = $component->get('values.address.street');
    $component->set('values.address.zip_code', '01234567')->call('lookupCep')->assertHasErrors('values.address.zip_code')->assertSet('values.address.street', $street);
});

test('identification renders with an empty document CPF and CNPJ selection', function (string $type) {
    $component = Livewire::test('granting-parties.form-fields')->set('values.document_type', $type)->assertSuccessful();
    expect($component->get('documentField')['label'])->toBe(match ($type) {
        'cpf' => 'CPF', 'cnpj' => 'CNPJ', default => 'Documento',
    })->and($component->get('canLookupCnpj'))->toBe($type === 'cnpj');
})->with(['', 'cpf', 'cnpj']);

test('document selection changes field rules and clears the previous document', function () {
    $component = Livewire::test('granting-parties.form-fields', ['grantingParty' => $this->party]);
    expect($component->get('documentField')['label'])->toBe('CNPJ')->and($component->get('documentField')['digits'])->toBe(14);
    $component->set('values.document_type', 'cpf')->assertSet('values.document_number', '');
    expect($component->get('documentField')['label'])->toBe('CPF')->and($component->get('documentField')['digits'])->toBe(11);
    $component->set('values.document_number', '529.982.247-25')->assertHasNoErrors();
    $component->set('values.document_number', '04.252.011/0001-10')->assertHasErrors('values.document_number');
    $component->set('values.document_type', 'cnpj')->assertSet('values.document_number', '')->assertHasNoErrors('values.document_number');
    $component->set('values.document_number', '529.982.247-25')->assertHasErrors('values.document_number');
});

function grantingPartyCompanyPayload(array $overrides = []): array
{
    return [...[
        'cnpj' => '04252011000110', 'razao_social' => 'Empresa consultada', 'nome_fantasia' => 'Unidade consultada',
        'email' => 'CONTATO@EXAMPLE.TEST', 'cnae_fiscal_descricao' => 'Serviços de engenharia',
        'ddd_telefone_1' => '5533334444', 'descricao_tipo_de_logradouro' => 'RUA', 'logradouro' => 'DAS FLORES',
        'numero' => '123', 'bairro' => 'Centro', 'cep' => '98780000', 'uf' => 'RS',
        'municipio' => 'Santa Rosa', 'codigo_municipio_ibge' => 4317202,
        'qsa' => [['nome_socio' => 'Não é o representante']],
    ], ...$overrides];
}

test('CNPJ preview works in creation and editing without persisting and resolves city by IBGE', function (bool $editing) {
    $city = City::factory()->create(['name' => 'Santa Rosa', 'state' => BrazilianState::RioGrandeDoSul, 'ibge_code' => '4317202']);
    Http::fake(['*cnpj/v1/*' => Http::response(grantingPartyCompanyPayload())]);
    $count = Address::count();
    $audit = Activity::count();
    $component = Livewire::test('granting-parties.form-fields', $editing ? ['grantingParty' => $this->party] : []);
    if (! $editing) {
        $component->set('values.document_type', 'cnpj');
    }
    $component->set('values.document_number', '04.252.011/0001-10')->set('values.name', 'Nome manual')
        ->set('values.representative_name', 'Representante manual')->call('lookupCnpj')
        ->assertHasNoErrors()->assertSet('values.name', 'Nome manual')->assertSet('showLookupPreview', true);
    $component->set('selectedCompanyName', 'trade_name')->call('applyCompanyLookup')
        ->assertSet('values.name', 'Unidade consultada')->assertSet('selectedCityId', $city->id)
        ->assertSet('values.phone', '(55) 3333-4444')->assertSet('values.address.street', 'RUA DAS FLORES')
        ->assertSet('values.address.zip_code', '98780-000')->assertSet('values.representative_name', 'Representante manual');
    expect(Address::count())->toBe($count)->and(Activity::count())->toBe($audit)
        ->and($this->party->fresh()->name)->not->toBe('Unidade consultada');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/cnpj/v1/04252011000110'));
})->with([false, true]);

test('CPF and invalid CNPJ cannot query the company service', function () {
    Http::fake();
    $component = Livewire::test('granting-parties.form-fields');
    $component->set('values.document_type', 'cnpj')->set('values.document_number', '52998224725')
        ->call('lookupCnpj')->assertHasErrors('values.document_number');
    Http::assertNothingSent();
    $component->set('values.document_type', 'cpf')->call('lookupCnpj')->assertForbidden();
    Http::assertNothingSent();
});

test('company preview is locked and refuses a changed document', function () {
    Http::fake(['*cnpj/v1/*' => Http::response(grantingPartyCompanyPayload())]);
    $component = Livewire::test('granting-parties.form-fields', ['grantingParty' => $this->party])->call('lookupCnpj');
    expect(fn () => $component->set('pendingCompanyLookup.legal_name', 'Adulterado'))->toThrow(CannotUpdateLockedPropertyException::class);
    $component->set('values.document_number', '11222333000181')->call('applyCompanyLookup')->assertHasErrors('values.document_number')->assertSet('showLookupPreview', false);
    $component->call('lookupCnpj')->set('values.document_type', 'cpf')->assertSet('pendingCompanyLookup', []);
});

test('company lookup failures cancellation and missing fields preserve manual data', function () {
    $partial = grantingPartyCompanyPayload(['ddd_telefone_1' => '', 'logradouro' => '', 'descricao_tipo_de_logradouro' => '', 'codigo_municipio_ibge' => null]);
    Http::fake(['*cnpj/v1/*' => Http::sequence()->push([], 503)->push($partial)->push($partial)]);
    $component = Livewire::test('granting-parties.form-fields', ['grantingParty' => $this->party])->set('values.name', 'Nome manual');
    $component->call('lookupCnpj')->assertHasErrors('values.document_number')->assertSet('values.name', 'Nome manual');
    $component->set('values.phone', '(55) 99999-9999')->set('values.address.street', 'Rua manual')->call('lookupCnpj');
    $component->call('cancelCompanyLookup')->assertSet('showLookupPreview', false)->assertSet('values.name', 'Nome manual');
    $component->call('lookupCnpj')->call('applyCompanyLookup')->assertSet('values.phone', '(55) 99999-9999')
        ->assertSet('values.address.street', 'Rua manual')->assertSet('selectedCityId', null);
});

test('company lookup reauthorizes query and application after context changes', function (string $change) {
    Http::fake(['*cnpj/v1/*' => Http::response(grantingPartyCompanyPayload())]);
    $component = Livewire::test('granting-parties.form-fields', ['grantingParty' => $this->party])->call('lookupCnpj');
    if ($change === 'campus') {
        $this->office->campus->deactivate();
    } else {
        $other = Affiliation::factory()->server()->for($this->office->user)->create();
        session()->put('active_affiliation_id', $other->id);
    }
    $component->call('applyCompanyLookup')->assertForbidden();
    Http::assertSentCount(1);
})->with(['campus', 'context']);

test('company preview includes principal activity and email and applies them only after confirmation', function () {
    Http::fake(['*cnpj/v1/*' => Http::response(grantingPartyCompanyPayload())]);
    $component = Livewire::test('granting-parties.form-fields', ['grantingParty' => $this->party])
        ->set('values.phone', '5533334444')->set('values.address.zip_code', '98780000')
        ->set('values.email', 'manual@example.test')->set('values.field_of_activity', 'Atividade manual')
        ->set('values.professional_council', 'Conselho informado')->call('lookupCnpj')
        ->assertSet('values.email', 'manual@example.test')->assertSet('values.field_of_activity', 'Atividade manual');
    expect(collect($component->instance()->lookupPreviewChanges())->pluck('label')->all())
        ->toContain('E-mail', 'Área de atuação')->not->toContain('Telefone', 'CEP');
    $component->call('applyCompanyLookup')->assertSet('values.email', 'contato@example.test')
        ->assertSet('values.field_of_activity', 'Serviços de engenharia')->assertSet('values.professional_council', 'Conselho informado');
    expect($this->party->fresh()->field_of_activity)->not->toBe('Serviços de engenharia');
});

test('missing or malformed API activity and email preserve manual input', function (mixed $email, mixed $activity) {
    Http::fake(['*cnpj/v1/*' => Http::response(grantingPartyCompanyPayload(['email' => $email, 'cnae_fiscal_descricao' => $activity]))]);
    Livewire::test('granting-parties.form-fields', ['grantingParty' => $this->party])
        ->set('values.email', 'manual@example.test')->set('values.field_of_activity', 'Atividade manual')
        ->call('lookupCnpj')->call('applyCompanyLookup')
        ->assertSet('values.email', 'manual@example.test')->assertSet('values.field_of_activity', 'Atividade manual');
})->with([[null, null], ['', ''], ['not-an-email', ['invalid']]]);

test('native granting party form submits its rendered field values', function () {
    $data = $this->party->only(['document_number', 'name', 'representative_name', 'representative_role', 'phone', 'email', 'field_of_activity', 'professional_council', 'council_registration_number', 'credentialing_process_number']);
    $data['document_type'] = $this->party->document_type->value;
    $data['address'] = $this->party->address->only(['city_id', 'street', 'number', 'neighborhood', 'zip_code']);
    $response = $this->withSession(['_old_input' => $data])->get(route('granting-parties.create'))->assertSuccessful();
    $dom = new DOMDocument;
    @$dom->loadHTML($response->getContent(), LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    $form = $xpath->query('//form[@action="'.route('granting-parties.store').'"]')->item(0);
    $fields = [];
    foreach ($xpath->query('.//input[@name]', $form) as $input) {
        if ($input->getAttribute('name') !== '' && ! $input->hasAttribute('disabled') && ! in_array($input->getAttribute('type'), ['radio', 'checkbox', 'submit'], true)) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }
    }
    foreach ($xpath->query('.//select[@name]', $form) as $select) {
        if ($select->getAttribute('name') !== '' && ! $select->hasAttribute('disabled')) {
            $selected = $xpath->query('.//option[@selected]', $select)->item(0) ?? $xpath->query('.//option', $select)->item(0);
            $fields[$select->getAttribute('name')] = $selected?->getAttribute('value') ?? '';
        }
    }
    parse_str(http_build_query($fields), $payload);
    $this->post(route('granting-parties.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
    expect(GrantingParty::count())->toBe(2);
});

test('a granting party can be created after applying Brasil API values with masked phone and CEP', function () {
    $city = City::factory()->create(['name' => 'Santa Rosa', 'state' => BrazilianState::RioGrandeDoSul, 'ibge_code' => '4317202']);
    Http::fake(['*cnpj/v1/*' => Http::response(grantingPartyCompanyPayload())]);

    $component = Livewire::test('granting-parties.form-fields')
        ->set('values.document_type', 'cnpj')
        ->set('values.document_number', '04.252.011/0001-10')
        ->set('values.representative_name', 'Representante informado')
        ->set('values.representative_role', 'Responsável técnico')
        ->call('lookupCnpj')
        ->call('applyCompanyLookup')
        ->assertHasNoErrors();

    $values = $component->get('values');
    $data = [
        ...$values,
        'document_type' => 'cnpj',
        'document_number' => '04.252.011/0001-10',
        'address' => [...$values['address'], 'city_id' => $city->id],
    ];

    $this->post(route('granting-parties.store'), $data)->assertSessionHasNoErrors()->assertRedirect();

    $created = GrantingParty::query()->latest('id')->firstOrFail();
    expect($created->phone)->toBe('5533334444')
        ->and($created->address->zip_code)->toBe('98780000')
        ->and($created->address->city_id)->toBe($city->id)
        ->and($created->representative_name)->toBe('Representante informado');
});
