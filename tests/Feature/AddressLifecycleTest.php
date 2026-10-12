<?php

use App\Models\Address;
use App\Models\Campus;
use App\Models\GrantingParty;
use App\Models\Internship;
use App\Models\UserPersonalData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

test('deleting a catalog owner physically removes its address in the same transaction', function (string $model) {
    $owner = $model::factory()->create();
    $address = $owner->address;
    $owner->delete();

    $this->assertModelMissing($owner);
    $this->assertModelMissing($address);
})->with(['campus' => [Campus::class], 'granting party' => [GrantingParty::class]]);

test('permanently deleting an unreferenced catalog owner removes its address and retains its audit identity', function (string $model) {
    $owner = $model::factory()->create(['name' => 'Cadastro removido']);
    $address = $owner->address;
    $owner->delete();

    $this->assertModelMissing($owner);
    expect(Address::query()->find($address->id))->toBeNull();
    $entry = Activity::forSubject($address)->where('event', 'deleted')->sole();
    expect($entry->properties->get('address_context')['owners'][0])->toMatchArray([
        'type' => $owner->getMorphClass(), 'id' => $owner->id, 'name' => 'Cadastro removido',
    ]);
})->with(['campus' => [Campus::class], 'granting party' => [GrantingParty::class]]);

test('deleting personal data or its account also removes its exclusive address', function (bool $deleteAccount) {
    $data = UserPersonalData::factory()->create();
    $address = $data->address;
    ($deleteAccount ? $data->user : $data)->delete();

    $this->assertModelMissing($data);
    expect(Address::query()->find($address->id))->toBeNull()
        ->and(Activity::forSubject($address)->where('event', 'deleted')->exists())->toBeTrue();
})->with([false, true]);

test('deleting an internship removes both exclusive historical addresses', function () {
    $stage = Internship::factory()->create();
    $ids = [$stage->student_address_id, $stage->workplace_address_id];
    $stage->delete();

    expect(Address::query()->whereKey($ids)->count())->toBe(0);
    foreach ($ids as $id) {
        expect(Activity::query()->where('subject_type', (new Address)->getMorphClass())
            ->where('subject_id', $id)->where('event', 'deleted')->count())->toBe(1);
    }
});

test('shared addresses remain available until their last active owner is removed', function () {
    $address = Address::factory()->create();
    $first = GrantingParty::factory()->for($address)->create();
    $second = GrantingParty::factory()->for($first->campus)->for($address)->create();
    $first->delete();
    expect(Address::find($address->id))->not->toBeNull()
        ->and($second->fresh()->address->is($address))->toBeTrue();
    $second->delete();
    $this->assertModelMissing($address);
});

test('deleting a catalog owner preserves its address when referenced by another historical internship', function () {
    $party = GrantingParty::factory()->create();
    $address = $party->address;
    $stage = Internship::factory()->create(['workplace_address_id' => $address->id]);
    $party->delete();

    expect(Address::find($address->id))->not->toBeNull()
        ->and($stage->fresh()->workplaceAddress->is($address))->toBeTrue();
});

test('failed address deletion rolls back its owner deletion and both audit events', function () {
    $party = GrantingParty::factory()->create();
    $address = $party->address;
    $before = Activity::count();
    $fail = true;
    Address::deleting(function (Address $target) use ($address, &$fail): void {
        if ($fail && $target->id === $address->id) {
            throw new RuntimeException('Falha ao excluir endereço');
        }
    });
    try {
        expect(fn () => $party->delete())->toThrow(RuntimeException::class);
    } finally {
        $fail = false;
    }
    expect(GrantingParty::find($party->id))->not->toBeNull()
        ->and(Address::find($address->id))->not->toBeNull()
        ->and(Activity::count())->toBe($before);
});

test('direct address deletion cannot orphan an active owner', function () {
    $party = GrantingParty::factory()->create();
    expect(fn () => DB::transaction(fn () => $party->address->delete()))->toThrow(QueryException::class);
    $this->assertModelExists($party->address);
});

test('new associations cannot select a physically deleted address', function (string $model) {
    $address = Address::factory()->create();
    $address->delete();
    expect(fn () => $model::factory()->create(['address_id' => $address->id]))->toThrow(ValidationException::class);
})->with(['campus' => [Campus::class], 'granting party' => [GrantingParty::class], 'personal data' => [UserPersonalData::class]]);

test('cancelled owner deletion cannot remove its address or emit deletion audit events', function () {
    $party = GrantingParty::factory()->create();
    $address = $party->address;
    $activities = Activity::count();
    GrantingParty::deleting(fn (GrantingParty $target): bool => $target->id !== $party->id);
    expect($party->delete())->toBeFalse();
    $this->assertModelExists($party);
    $this->assertModelExists($address);
    expect(Activity::count())->toBe($activities);
});

test('deleting an internship with one shared address in both fields deletes the address once', function () {
    $address = Address::factory()->create();
    $stage = Internship::factory()->create(['student_address_id' => $address->id, 'workplace_address_id' => $address->id]);
    $stage->delete();
    $this->assertModelMissing($address);
    expect(Activity::forSubject($address)->where('event', 'deleted')->count())->toBe(1);
});
