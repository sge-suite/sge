<?php

use App\Enums\AffiliationType;
use App\Models\Address;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\City;
use App\Models\Internship;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

function campusManagementInput(City $city): array
{
    return [
        'name' => 'Campus de Teste',
        'cnpj' => '04.252.011/0001-10',
        'phone' => '(55) 99999-9999',
        'email' => 'campus@example.test',
        'legal_representative_name' => 'Pessoa Responsável',
        'legal_representative_position' => 'Diretor(a) Geral',
        'insurance_company_name' => null,
        'insurance_policy_number' => null,
        'address' => [
            'city_id' => $city->id,
            'street' => 'Rua Institucional',
            'number' => '123',
            'neighborhood' => 'Centro',
            'zip_code' => '98765000',
        ],
    ];
}

function systemAdministratorFor(User $user): Affiliation
{
    return Affiliation::factory()->global()->for($user)->create();
}

function campusAdministratorFor(User $user, Campus $campus): Affiliation
{
    return Affiliation::factory()->for($user)->create([
        'type' => AffiliationType::CampusAdministrator,
        'campus_id' => $campus->id,
        'course_id' => null,
    ]);
}

test('system administrators can create a campus with its own address and active affiliation authorship', function () {
    $user = User::factory()->create();
    $affiliation = systemAdministratorFor($user);
    $city = City::factory()->create();
    $payload = campusManagementInput($city);
    $addressCount = Address::count();

    $this->actingAs($user)
        ->postJson(route('campuses.store'), $payload)
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', 'Campus criado com sucesso.');

    $campus = Campus::query()->sole();
    $address = $campus->address;
    $activity = Activity::forSubject($campus)->where('event', 'created')->sole();
    $addressActivity = Activity::forSubject($address)->where('event', 'created')->sole();

    expect($address->city_id)->toBe($city->id)
        ->and($campus->cnpj)->toBe('04252011000110')
        ->and($campus->phone)->toBe('55999999999')
        ->and(Address::count())->toBe($addressCount + 1)
        ->and($activity->causer->is($affiliation))->toBeTrue()
        ->and($addressActivity->causer->is($affiliation))->toBeTrue()
        ->and($activity->properties->get('user_id'))->toBe($user->id)
        ->and($activity->properties->get('affiliation_id'))->toBe($affiliation->id);
});

test('campus creation rejects invalid cnpj and fields outside the input contract without partial rows', function () {
    $user = User::factory()->create();
    systemAdministratorFor($user);
    $payload = campusManagementInput(City::factory()->create());
    $addressCount = Address::count();

    $this->actingAs($user)
        ->postJson(route('campuses.store'), [...$payload, 'deactivated_at' => now()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['deactivated_at']);

    $this->postJson(route('campuses.store'), [
        ...$payload,
        'id' => 10,
        'created_at' => now(),
        'address_id' => 10,
        'address' => [...$payload['address'], 'id' => 10],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['id', 'created_at', 'address_id', 'address.id']);

    $this->postJson(route('campuses.store'), [...$payload, 'cnpj' => '11111111111111'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cnpj']);

    expect(Campus::count())->toBe(0)
        ->and(Address::count())->toBe($addressCount);
});

test('only system administrators can create campuses', function () {
    $user = User::factory()->create();
    $campus = Campus::factory()->create();
    campusAdministratorFor($user, $campus);

    $this->actingAs($user)
        ->postJson(route('campuses.store'), campusManagementInput(City::factory()->create()))
        ->assertForbidden();

    expect(Campus::count())->toBe(1);
});

test('the active affiliation determines campus permissions when one account has multiple affiliations', function () {
    $user = User::factory()->create();
    $campus = Campus::factory()->create();
    $campusAdministrator = campusAdministratorFor($user, $campus);
    $systemAdministrator = systemAdministratorFor($user);
    $payload = campusManagementInput(City::factory()->create());

    $this->actingAs($user)->withSession(['active_affiliation_id' => $campusAdministrator->id]);

    $this->postJson(route('campuses.store'), $payload)->assertForbidden();
    $this->putJson(route('campuses.update', $campus), ['name' => 'Nome não permitido'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->withSession(['active_affiliation_id' => $systemAdministrator->id]);
    $this->postJson(route('campuses.store'), $payload)->assertRedirect(route('dashboard'));

    expect(Campus::count())->toBe(2);
});

test('an invalid or deactivated affiliation cannot authorize campus mutations', function () {
    $user = User::factory()->create();
    $campus = Campus::factory()->create();
    $affiliation = campusAdministratorFor($user, $campus);
    $otherAffiliation = Affiliation::factory()->global()->create();

    $this->actingAs($user)
        ->withSession(['active_affiliation_id' => $otherAffiliation->id])
        ->putJson(route('campuses.update', $campus), ['phone' => '(55) 98888-7777'])
        ->assertRedirect(route('affiliations.select'));

    $affiliation->update(['deactivated_at' => now()]);
    $this->withSession([
        'active_affiliation_id' => $affiliation->id,
        'active_affiliation_needs_choice' => false,
    ])
        ->putJson(route('campuses.update', $campus), ['phone' => '(55) 98888-7777'])
        ->assertRedirect(route('affiliations.select'));

    expect($campus->fresh()->phone)->not->toBe('55988887777');
});

test('campus administrators can edit only phone representative and insurance fields for their own active campus', function () {
    $user = User::factory()->create();
    $campus = Campus::factory()->create();
    $otherCampus = Campus::factory()->create();
    $affiliation = campusAdministratorFor($user, $campus);
    $addressId = $campus->address_id;

    $this->actingAs($user)
        ->putJson(route('campuses.update', $campus), [
            'phone' => '(55) 98888-7777',
            'legal_representative_name' => 'Nova Responsável',
            'legal_representative_position' => 'Direção',
            'insurance_company_name' => 'Seguradora Institucional',
            'insurance_policy_number' => 'APOL-2026-01',
        ])
        ->assertRedirect(route('dashboard'));

    expect($campus->fresh()->phone)->toBe('55988887777')
        ->and($campus->fresh()->legal_representative_name)->toBe('Nova Responsável')
        ->and($campus->fresh()->insurance_policy_number)->toBe('APOL-2026-01')
        ->and($campus->fresh()->address_id)->toBe($addressId)
        ->and(Activity::forSubject($campus)->where('event', 'updated')->sole()->causer->is($affiliation))->toBeTrue();

    $this->putJson(route('campuses.update', $campus), ['email' => 'forbidden@example.test'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    $this->putJson(route('campuses.update', $otherCampus), ['phone' => '(55) 98888-7777'])
        ->assertForbidden();
    $this->patchJson(route('campuses.deactivate', $campus), ['current_password' => 'password'])
        ->assertForbidden();
    $this->patchJson(route('campuses.reactivate', $campus))
        ->assertForbidden();

    expect($campus->fresh()->email)->not->toBe('forbidden@example.test');
});

test('system administrators can update campus fields and the existing owned address in one audited transaction', function () {
    $user = User::factory()->create();
    $affiliation = systemAdministratorFor($user);
    $campus = Campus::factory()->create(['name' => 'Nome Anterior']);
    $address = $campus->address;
    $addressId = $address->id;
    $payload = campusManagementInput(City::factory()->create());

    $this->actingAs($user)
        ->putJson(route('campuses.update', $campus), $payload)
        ->assertRedirect(route('dashboard'));

    $campusActivity = Activity::forSubject($campus)->where('event', 'updated')->sole();
    $addressActivity = Activity::forSubject($address)->where('event', 'updated')->sole();

    expect($campus->fresh()->name)->toBe('Campus de Teste')
        ->and($campus->fresh()->address_id)->toBe($addressId)
        ->and($address->fresh()->street)->toBe('Rua Institucional')
        ->and($address->fresh()->city_id)->toBe($payload['address']['city_id'])
        ->and($campusActivity->attribute_changes->get('old'))->toMatchArray(['name' => 'Nome Anterior'])
        ->and($campusActivity->attribute_changes->get('attributes'))->toMatchArray(['name' => 'Campus de Teste'])
        ->and($campusActivity->causer->is($affiliation))->toBeTrue()
        ->and($addressActivity->attribute_changes->get('old'))->toMatchArray(['street' => $address->getOriginal('street')])
        ->and($addressActivity->attribute_changes->get('attributes'))->toMatchArray(['street' => 'Rua Institucional'])
        ->and($addressActivity->properties->get('affiliation_id'))->toBe($affiliation->id);
});

test('campus address updates reject references from other owners and historical internships', function () {
    $user = User::factory()->create();
    systemAdministratorFor($user);
    $campus = Campus::factory()->create();
    $originalStreet = $campus->address->street;
    $city = City::factory()->create();
    $payload = ['address' => [
        'city_id' => $city->id,
        'street' => 'Tentativa de alteração',
        'number' => '10',
        'neighborhood' => 'Centro',
    ]];

    Internship::factory()->create(['workplace_address_id' => $campus->address_id]);

    $this->actingAs($user)
        ->putJson(route('campuses.update', $campus), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['address']);

    expect($campus->address->fresh()->street)->toBe($originalStreet)
        ->and(Activity::forSubject($campus->address)->where('event', 'updated'))->toHaveCount(0);
});

test('address and campus updates roll back together if the campus write fails', function () {
    $user = User::factory()->create();
    systemAdministratorFor($user);
    $campus = Campus::factory()->create(['name' => 'Nome Original']);
    $address = $campus->address;
    $oldStreet = $address->street;
    $activityCount = Activity::count();
    $payload = campusManagementInput(City::factory()->create());
    Campus::updating(function (Campus $campus): never {
        throw new RuntimeException('Falha simulada ao atualizar o campus.');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)->putJson(route('campuses.update', $campus), $payload))
        ->toThrow(RuntimeException::class);

    expect($address->fresh()->street)->toBe($oldStreet)
        ->and($campus->fresh()->name)->toBe('Nome Original')
        ->and(Activity::count())->toBe($activityCount);
});

test('campus visibility is scoped to the active affiliation and includes inactive campus records', function () {
    $systemUser = User::factory()->create();
    $systemAffiliation = systemAdministratorFor($systemUser);
    $campusUser = User::factory()->create();
    $ownCampus = Campus::factory()->deactivated()->create();
    $otherCampus = Campus::factory()->deactivated()->create();
    $campusAffiliation = campusAdministratorFor($campusUser, $ownCampus);

    $this->actingAs($systemUser)->withSession(['active_affiliation_id' => $systemAffiliation->id]);

    expect(Gate::allows('viewAny', Campus::class))->toBeTrue()
        ->and(Campus::query()->visibleTo($systemAffiliation)->pluck('id')->all())
        ->toEqualCanonicalizing([$ownCampus->id, $otherCampus->id]);

    $this->actingAs($campusUser)->withSession(['active_affiliation_id' => $campusAffiliation->id]);

    expect(Gate::allows('viewAny', Campus::class))->toBeTrue()
        ->and(Gate::allows('view', $ownCampus))->toBeTrue()
        ->and(Gate::allows('view', $otherCampus))->toBeFalse()
        ->and(Campus::query()->visibleTo($campusAffiliation)->pluck('id')->all())->toBe([$ownCampus->id]);
});

test('campus deactivation requires the current password and does not mutate data on failure', function (string $password) {
    $user = User::factory()->create(['password' => 'correta-senha']);
    systemAdministratorFor($user);
    $campus = Campus::factory()->create();
    $oldUpdatedAt = $campus->updated_at;
    $activityCount = Activity::count();

    $this->actingAs($user)
        ->patchJson(route('campuses.deactivate', $campus), ['current_password' => $password])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);

    expect($campus->fresh()->deactivated_at)->toBeNull()
        ->and($campus->fresh()->updated_at->equalTo($oldUpdatedAt))->toBeTrue()
        ->and(Activity::count())->toBe($activityCount);
})->with([
    'incorrect password' => 'senha-incorreta',
    'empty password' => '',
]);

test('campus deactivation requires the current password field', function () {
    $user = User::factory()->create(['password' => 'correta-senha']);
    systemAdministratorFor($user);
    $campus = Campus::factory()->create();

    $this->actingAs($user)
        ->patchJson(route('campuses.deactivate', $campus), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);

    expect($campus->fresh()->deactivated_at)->toBeNull();
});

test('system administrator deactivation audits the transition without recording the password and preserves affiliations', function () {
    $this->freezeSecond();
    $plainPassword = 'senha-corretissima-2026';
    $user = User::factory()->create(['password' => $plainPassword]);
    $affiliation = systemAdministratorFor($user);
    $campus = Campus::factory()->create();
    $campusAffiliation = campusAdministratorFor(User::factory()->create(), $campus);
    $campusAffiliation->update(['last_used_at' => now()->subDay()]);
    $lastUsedAt = $campusAffiliation->fresh()->last_used_at;

    $this->actingAs($user)
        ->patchJson(route('campuses.deactivate', $campus), ['current_password' => $plainPassword])
        ->assertRedirect(route('dashboard'));

    $activity = Activity::forSubject($campus)->where('event', 'updated')->sole();
    $campusAffiliation = $campusAffiliation->fresh();

    expect($campus->fresh()->deactivated_at)->not->toBeNull()
        ->and($campusAffiliation->deactivated_at)->toBeNull()
        ->and($campusAffiliation->last_used_at->equalTo($lastUsedAt))->toBeTrue()
        ->and($activity->attribute_changes->get('old')['deactivated_at'])->toBeNull()
        ->and($activity->attribute_changes->get('attributes')['deactivated_at'])->not->toBeNull()
        ->and($activity->causer->is($affiliation))->toBeTrue()
        ->and($activity->properties->get('user_id'))->toBe($user->id)
        ->and($activity->properties->get('affiliation_id'))->toBe($affiliation->id)
        ->and(Activity::all()->toJson())->not->toContain($plainPassword)
        ->and(Activity::all()->toJson())->not->toContain($user->fresh()->password);
});

test('deactivation is idempotent and inactive campus cannot be edited until reactivated', function () {
    $this->freezeSecond();
    $plainPassword = 'senha-correta-para-campus';
    $user = User::factory()->create(['password' => $plainPassword]);
    systemAdministratorFor($user);
    $campus = Campus::factory()->create();

    $this->actingAs($user)
        ->patchJson(route('campuses.deactivate', $campus), ['current_password' => $plainPassword])
        ->assertRedirect(route('dashboard'));

    $deactivatedAt = $campus->fresh()->deactivated_at;
    $activityCount = Activity::count();

    $this->patchJson(route('campuses.deactivate', $campus), ['current_password' => $plainPassword])
        ->assertRedirect(route('dashboard'));
    $this->putJson(route('campuses.update', $campus), ['name' => 'Não deve salvar'])
        ->assertForbidden();

    expect($campus->fresh()->deactivated_at->equalTo($deactivatedAt))->toBeTrue()
        ->and($campus->fresh()->name)->not->toBe('Não deve salvar')
        ->and(Activity::count())->toBe($activityCount);
});

test('system administrator can reactivate without password and the model lifecycle methods are idempotent', function () {
    $user = User::factory()->create();
    systemAdministratorFor($user);
    $campus = Campus::factory()->deactivated()->create();
    $deactivatedAt = $campus->deactivated_at;

    $this->actingAs($user)
        ->patchJson(route('campuses.reactivate', $campus))
        ->assertRedirect(route('dashboard'));

    $activity = Activity::forSubject($campus)->where('event', 'updated')->sole();

    expect($campus->fresh()->deactivated_at)->toBeNull()
        ->and($activity->attribute_changes->get('old')['deactivated_at'])->not->toBeNull()
        ->and($activity->attribute_changes->get('attributes')['deactivated_at'])->toBeNull()
        ->and($deactivatedAt)->not->toBeNull()
        ->and($campus->fresh()->reactivate())->toBeFalse();
});

test('personal account settings remain accessible when its active campus is deactivated', function () {
    $user = User::factory()->create();
    $campus = Campus::factory()->create();
    campusAdministratorFor($user, $campus);
    $campus->deactivate();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();

    expect($campus->fresh()->deactivated_at)->not->toBeNull();
});

test('inactive campus rejects model writes except reactivation', function () {
    $campus = Campus::factory()->deactivated()->create();

    expect(fn () => $campus->fresh()->update(['name' => 'Alteração bloqueada']))
        ->toThrow(ValidationException::class)
        ->and($campus->fresh()->name)->not->toBe('Alteração bloqueada')
        ->and($campus->fresh()->reactivate())->toBeTrue()
        ->and($campus->fresh()->deactivated_at)->toBeNull();
});
