<?php

use App\Actions\ManageGrantingParty;
use App\Enums\AffiliationType;
use App\Models\Address;
use App\Models\Affiliation;
use App\Models\Course;
use App\Models\GrantingParty;
use App\Models\GrantingPartyRegistrationRequest;
use App\Models\Internship;
use App\Models\InternshipRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

function grantingPartyPayload(GrantingParty $party): array
{
    return [...$party->only(['document_type', 'document_number', 'name', 'representative_name', 'representative_role', 'phone', 'email', 'field_of_activity', 'professional_council', 'council_registration_number', 'credentialing_process_number']), 'document_type' => $party->document_type->value, 'address' => $party->address->only(['city_id', 'street', 'number', 'neighborhood', 'zip_code'])];
}

beforeEach(function () {
    $this->office = Affiliation::factory()->server()->create();
    $this->actingAs($this->office->user)->withSession(['active_affiliation_id' => $this->office->id]);
    $this->party = GrantingParty::factory()->for($this->office->campus)->create();
    $this->data = grantingPartyPayload($this->party);
});

test('office creates duplicate documents with exclusive addresses without creating accounts', function () {
    $users = User::count();
    $this->post(route('granting-parties.store'), $this->data)->assertSessionHasNoErrors()->assertRedirect();
    $created = GrantingParty::latest('id')->first();
    expect($created->campus_id)->toBe($this->office->campus_id)->and($created->deactivated_at)->toBeNull()
        ->and($created->address_id)->not->toBe($this->party->address_id)->and(User::count())->toBe($users);
    expect(Activity::forSubject($created)->sole()->causer_id)->toBe($this->office->id);
});

test('updates current data and replaces only changed addresses preserving stage history', function () {
    $stage = Internship::factory()->create(['granting_party_id' => $this->party->id, 'course_id' => Course::factory()->for($this->office->campus)]);
    $old = $this->party->address;
    $snapshot = $stage->granting_party_snapshot;
    $data = [...$this->data, 'name' => 'Nome corrigido'];
    $this->put(route('granting-parties.update', $this->party), $data)->assertSessionHasNoErrors();
    expect($this->party->fresh()->address_id)->toBe($old->id);
    $data['address']['street'] = 'Novo logradouro';
    $this->put(route('granting-parties.update', $this->party), $data)->assertSessionHasNoErrors();
    expect($this->party->fresh()->address_id)->not->toBe($old->id)->and($old->fresh()->street)->toBe($old->street)
        ->and($stage->fresh()->granting_party_snapshot)->toBe($snapshot);
});

test('validates forms and action input without partial records', function (string $field, mixed $value) {
    $data = $this->data;
    data_set($data, $field, $value);
    $count = Address::count();
    $activities = Activity::count();
    $this->post(route('granting-parties.store'), $data)->assertSessionHasErrors();
    expect(fn () => app(ManageGrantingParty::class)->handle($this->office->user, null, 'create', $data))->toThrow(ValidationException::class);
    expect(Address::count())->toBe($count)->and(GrantingParty::count())->toBe(1)->and(Activity::count())->toBe($activities);
})->with([
    ['name', ''], ['name', str_repeat('a', 256)], ['name', ['bad']], ['document_type', 'rg'],
    ['document_number', '11111111111'], ['phone', 'invalid'], ['email', 'invalid'],
    ['representative_name', ''], ['representative_role', ''], ['field_of_activity', ''],
    ['professional_council', str_repeat('a', 121)], ['council_registration_number', str_repeat('a', 65)],
    ['credentialing_process_number', str_repeat('a', 101)], ['address.city_id', PHP_INT_MAX],
    ['address.zip_code', 'a01234567'], ['address.street', ''], ['address.number', ''], ['address.neighborhood', ''],
    ['address.campus_id', 1], ['campus_id', 1], ['address_id', 1], ['deactivated_at', '2026-10-01'], ['deleted_at', '2026-10-01'],
]);

test('only selected office context authorizes all catalog operations', function (AffiliationType $type) {
    $factory = match ($type) {
        AffiliationType::Student => Affiliation::factory()->student(), AffiliationType::Supervisor => Affiliation::factory()->supervisor(), default => Affiliation::factory()
    };
    $other = $factory->for($this->office->user)->create(['type' => $type]);
    $this->withSession(['active_affiliation_id' => $other->id]);
    foreach (['viewAny', 'create'] as $ability) {
        expect(Gate::allows($ability, GrantingParty::class))->toBeFalse();
    }
    foreach (['view', 'update', 'delete', 'deactivate', 'reactivate'] as $ability) {
        expect(Gate::allows($ability, $this->party))->toBeFalse();
    }
    $this->get(route('granting-parties.index'))->assertForbidden();
    $this->post(route('granting-parties.store'), $this->data)->assertForbidden();
})->with(array_values(array_filter(AffiliationType::cases(), fn ($type) => $type !== AffiliationType::InternshipOffice)));

test('foreign campuses are inaccessible by routes and action', function () {
    $foreign = GrantingParty::factory()->create();
    $this->get(route('granting-parties.show', $foreign))->assertForbidden();
    $this->get(route('granting-parties.edit', $foreign))->assertForbidden();
    $this->put(route('granting-parties.update', $foreign), $this->data)->assertForbidden();
    $this->patch(route('granting-parties.deactivate', $foreign), ['current_password' => 'password'])->assertForbidden();
    expect(fn () => app(ManageGrantingParty::class)->handle($this->office->user, $foreign, 'delete', ['current_password' => 'password']))->toThrow(AuthorizationException::class);
});

test('inactive campus keeps reading and freezes every write', function () {
    $this->office->campus->deactivate();
    $this->get(route('granting-parties.show', $this->party))->assertSuccessful();
    $this->post(route('granting-parties.store'), $this->data)->assertForbidden();
    $this->put(route('granting-parties.update', $this->party), $this->data)->assertForbidden();
    foreach (['deactivate', 'reactivate'] as $operation) {
        $this->patch(route('granting-parties.'.$operation, $this->party))->assertForbidden();
    }
    expect(Gate::allows('delete', $this->party))->toBeFalse();
});

test('lifecycle requires password preserves inactive editing and rejects repeat transitions', function () {
    $this->patch(route('granting-parties.deactivate', $this->party), ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    $this->patch(route('granting-parties.deactivate', $this->party), ['current_password' => 'password'])->assertSessionHasNoErrors();
    $this->patch(route('granting-parties.deactivate', $this->party), ['current_password' => 'password'])->assertForbidden();
    $this->put(route('granting-parties.update', $this->party), [...$this->data, 'name' => 'Corrigido'])->assertSessionHasNoErrors();
    expect($this->party->fresh()->deactivated_at)->not->toBeNull();
    $this->patch(route('granting-parties.reactivate', $this->party))->assertSessionHasNoErrors();
    $this->patch(route('granting-parties.reactivate', $this->party))->assertForbidden();
    expect(Activity::forSubject($this->party)->get()->toJson())->not->toContain('current_password');
});

test('physical deletion also deletes its owned address and retains audit but rejects business references', function (string $relation) {
    if ($relation === 'registration') {
        GrantingPartyRegistrationRequest::factory()->for($this->office->campus)->create(['granting_party_id' => $this->party->id]);
    }
    if ($relation === 'request') {
        InternshipRequest::factory()->create(['granting_party_id' => $this->party->id, 'affiliation_id' => Affiliation::factory()->student()->for($this->office->campus)]);
    }
    if ($relation === 'internship') {
        Internship::factory()->create(['granting_party_id' => $this->party->id, 'course_id' => Course::factory()->for($this->office->campus)]);
    }
    if ($relation !== 'none') {
        expect(fn () => $this->party->delete())->toThrow(ValidationException::class);
        expect(Gate::allows('delete', $this->party))->toBeFalse();
    } else {
        app(ManageGrantingParty::class)->handle($this->office->user, $this->party, 'delete', ['current_password' => 'password']);
        $this->assertModelMissing($this->party);
        $this->assertModelMissing($this->party->address);
    }
})->with(['none', 'registration', 'request', 'internship']);

test('new associations reject inactive granting parties while existing references remain valid', function () {
    $request = InternshipRequest::factory()->create(['granting_party_id' => $this->party->id, 'affiliation_id' => Affiliation::factory()->student()->for($this->office->campus)]);
    $this->party->update(['deactivated_at' => now()]);
    $request->update(['observations' => 'Preservado']);
    expect(fn () => InternshipRequest::factory()->create(['granting_party_id' => $this->party->id]))->toThrow(ValidationException::class);
    expect(fn () => Internship::factory()->create(['granting_party_id' => $this->party->id]))->toThrow(ValidationException::class);
});

test('guests and unavailable selected affiliations cannot access the catalog', function (string $context) {
    if ($context === 'guest') {
        auth()->logout();
        $this->get(route('granting-parties.index'))->assertRedirect(route('login'));

        return;
    }
    if ($context === 'inactive') {
        $this->office->update(['deactivated_at' => now()]);
    }
    if ($context === 'foreign') {
        session()->put('active_affiliation_id', Affiliation::factory()->server()->create()->id);
    }
    if ($context === 'missing') {
        session()->put('active_affiliation_id', 999999);
    }
    if ($context === 'inactive') {
        $this->get(route('granting-parties.index'))->assertForbidden();
    } else {
        $this->get(route('granting-parties.index'))->assertRedirect(route('affiliations.select'));
    }
})->with(['guest', 'inactive', 'foreign', 'missing']);

test('action rejects incorrect lifecycle password independently of HTTP validation', function (string $operation) {
    expect(fn () => app(ManageGrantingParty::class)->handle($this->office->user, $this->party, $operation, ['current_password' => 'bad']))->toThrow(ValidationException::class);
    expect($this->party->fresh()->deactivated_at)->toBeNull()->and($this->party->fresh()->exists)->toBeTrue();
})->with(['deactivate', 'delete']);

test('action rechecks selected context after acquiring locks', function () {
    $other = Affiliation::factory()->server()->for($this->office->user)->create();
    $triggered = false;
    DB::listen(function ($query) use ($other, &$triggered): void {
        if (! $triggered && str_contains($query->sql, 'for update')) {
            $triggered = true;
            session()->put('active_affiliation_id', $other->id);
        }
    });
    expect(fn () => app(ManageGrantingParty::class)->handle($this->office->user, null, 'create', $this->data))->toThrow(HttpException::class);
    expect(GrantingParty::count())->toBe(1);
});

test('failed persistence rolls back new address and audit', function () {
    $addresses = Address::count();
    $activities = Activity::count();
    GrantingParty::creating(function (): never {
        throw new RuntimeException('simulated failure');
    });
    try {
        expect(fn () => app(ManageGrantingParty::class)->handle($this->office->user, null, 'create', $this->data))->toThrow(RuntimeException::class);
        expect(Address::count())->toBe($addresses)->and(Activity::count())->toBe($activities);
    } finally {
        GrantingParty::clearBootedModels();
    }
});

test('legacy campus administrator retains the office catalog permissions', function () {
    DB::table('affiliations')->where('id', $this->office->id)->update(['type' => AffiliationType::LEGACY_CAMPUS_ADMINISTRATOR]);
    $this->get(route('granting-parties.index'))->assertSuccessful();
    $this->post(route('granting-parties.store'), $this->data)->assertSessionHasNoErrors();
});

test('inactive party keeps existing internship writable and cannot replace another reference', function () {
    $stage = Internship::factory()->create(['granting_party_id' => $this->party->id, 'course_id' => Course::factory()->for($this->office->campus)]);
    $addressId = $stage->workplace_address_id;
    $snapshot = $stage->granting_party_snapshot;
    $this->party->update(['deactivated_at' => now()]);
    $stage->update(['observations' => 'Registro mantido']);
    expect($stage->fresh()->workplace_address_id)->toBe($addressId)->and($stage->fresh()->granting_party_snapshot)->toBe($snapshot);
    $request = InternshipRequest::factory()->create(['affiliation_id' => Affiliation::factory()->student()->for($this->office->campus)]);
    expect(fn () => $request->update(['granting_party_id' => $this->party->id]))->toThrow(ValidationException::class);
});
