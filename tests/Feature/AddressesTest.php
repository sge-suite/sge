<?php

use App\Actions\CopyAddress;
use App\Enums\BrazilianState;
use App\Models\Address;
use App\Models\City;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
});

test('creates the addresses schema with exact PostgreSQL types and constraints', function () {
    expect(DB::getDriverName())->toBe('pgsql');
    $columns = collect(Schema::getColumns('addresses'))->keyBy('name');

    expect($columns->keys()->all())->toBe([
        'id', 'city_id', 'street', 'number', 'neighborhood', 'zip_code', 'created_at', 'updated_at',
    ]);

    foreach ([
        'id' => ['bigint', false],
        'city_id' => ['bigint', false],
        'street' => ['character varying(255)', false],
        'number' => ['character varying(255)', false],
        'neighborhood' => ['character varying(255)', false],
        'zip_code' => ['character varying(255)', true],
        'created_at' => ['timestamp(0) without time zone', true],
        'updated_at' => ['timestamp(0) without time zone', true],
    ] as $name => [$type, $nullable]) {
        expect($columns->get($name))->toMatchArray(['type' => $type, 'nullable' => $nullable]);
    }

    $indexes = collect(Schema::getIndexes('addresses'));
    expect($indexes->firstWhere('primary', true))->toMatchArray([
        'columns' => ['id'], 'primary' => true, 'unique' => true,
    ])
        ->and($indexes->firstWhere('name', 'addresses_city_id_index'))->toMatchArray([
            'columns' => ['city_id'], 'unique' => false,
        ])
        ->and(Schema::getForeignKeys('addresses'))->toHaveCount(1)
        ->and(Schema::getForeignKeys('addresses')[0])->toMatchArray([
            'columns' => ['city_id'],
            'foreign_table' => 'cities',
            'foreign_columns' => ['id'],
            'on_delete' => 'restrict',
        ]);
});

test('rolls back and reapplies migrations from addresses onwards on PostgreSQL', function () {
    $paths = glob(database_path('migrations/*_create_addresses_table.php'));
    expect($paths)->toHaveCount(1);
    $addressMigration = pathinfo($paths[0], PATHINFO_FILENAME);
    $steps = DB::table('migrations')->where('migration', '>=', $addressMigration)->count();

    expect($steps)->toBeGreaterThan(0);
    $this->artisan('migrate:rollback', ['--step' => $steps, '--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('addresses'))->toBeFalse()
        ->and(Schema::hasTable('cities'))->toBeTrue()
        ->and(Schema::hasTable('user_personal_data'))->toBeFalse();

    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('addresses'))->toBeTrue()
        ->and(Schema::hasTable('user_personal_data'))->toBeTrue();
});

test('enforces required columns in PostgreSQL even when model validation is bypassed', function (string $field) {
    $attributes = Address::factory()->for(City::factory()->create())->raw();
    $attributes['created_at'] = now();
    $attributes['updated_at'] = now();
    unset($attributes[$field]);

    expect(fn () => DB::transaction(fn () => Address::query()->insert($attributes)))
        ->toThrow(QueryException::class);
})->with(['city_id', 'street', 'number', 'neighborhood']);

test('allows nullable Laravel timestamps when inserting without Eloquent', function () {
    $attributes = Address::factory()->for(City::factory()->create())->raw();
    $id = Address::query()->insertGetId($attributes);
    $address = Address::query()->findOrFail($id);

    expect($address->created_at)->toBeNull()
        ->and($address->updated_at)->toBeNull();
});

test('enforces the city foreign key even when model validation is bypassed', function () {
    $attributes = Address::factory()->for(City::factory()->create())->raw();
    $attributes['city_id'] = PHP_INT_MAX;
    $attributes['created_at'] = now();
    $attributes['updated_at'] = now();

    expect(fn () => DB::transaction(fn () => Address::query()->insert($attributes)))
        ->toThrow(QueryException::class);
});

test('prevents deleting a city referenced by current and copied addresses', function () {
    $address = Address::factory()->create();
    $copy = (new CopyAddress)->handle($address);
    $city = $address->city()->sole();

    expect(fn () => DB::transaction(fn () => $city->delete()))->toThrow(QueryException::class);
    $this->assertModelExists($city);
    $this->assertModelExists($address);
    $this->assertModelExists($copy);
});

test('creates addresses through factories and exposes the city state without duplicating it', function () {
    $this->freezeSecond();
    $city = City::factory()->create(['state' => BrazilianState::RioGrandeDoSul]);
    $address = Address::factory()->for($city)->create(['zip_code' => '01234567', 'number' => '123 A']);
    $address = $address->fresh()->load('city');
    $city->load('addresses');

    expect($address->city_id)->toBeInt()
        ->and($address->zip_code)->toBe('01234567')
        ->and($address->number)->toBe('123 A')
        ->and($address->city->is($city))->toBeTrue()
        ->and($address->city->state)->toBe(BrazilianState::RioGrandeDoSul)
        ->and($city->addresses->sole()->is($address))->toBeTrue()
        ->and($address->created_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($address->updated_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($address->created_at->getTimezone()->getName())->toBe(config('app.timezone'))
        ->and($address->created_at->micro)->toBe(0)
        ->and(City::count())->toBe(1);

    Http::assertNothingSent();
});

test('accepts textual address numbers including s/n', function (string $number) {
    expect(Address::factory()->create(['number' => $number])->fresh()->number)->toBe($number);
})->with(['123', '0', '123 A', 's/n', 'KM 10', str_repeat('a', 255)]);

test('accepts the default string limit including multibyte text', function () {
    $address = Address::factory()->create([
        'street' => str_repeat('á', 255),
        'number' => str_repeat('a', 255),
        'neighborhood' => str_repeat('ã', 255),
        'zip_code' => '123456789',
    ]);
    expect($address->fresh()->street)->toHaveLength(255)
        ->and($address->fresh()->number)->toHaveLength(255)
        ->and($address->fresh()->neighborhood)->toHaveLength(255)
        ->and($address->fresh()->zip_code)->toBe('123456789');
});

test('normalizes an omitted or empty optional CEP to null', function (?string $zipCode) {
    $attributes = [
        'city_id' => City::factory()->create()->id,
        'street' => 'Rua de Teste',
        'number' => 's/n',
        'neighborhood' => 'Centro',
    ];
    if ($zipCode !== null) {
        $attributes['zip_code'] = $zipCode;
    }
    $address = Address::query()->create($attributes);

    expect($address->fresh()->zip_code)->toBeNull()
        ->and($address->fresh()->getRawOriginal('zip_code'))->toBeNull();
})->with([null, '', '   ']);

test('persists optional CEP values without format validation or sanitization', function (string $zipCode) {
    $address = Address::factory()->create(['zip_code' => $zipCode]);

    expect($address->zip_code)->toBe($zipCode)
        ->and($address->fresh()->zip_code)->toBe($zipCode);
})->with(['1234567', '1234567a', 'abc', '0', '12-34567']);

test('validates required address fields before persisting', function (string $field, mixed $value) {
    expect(fn () => Address::factory()->create([$field => $value]))->toThrow(ValidationException::class);
    expect(Address::count())->toBe(0)
        ->and(Activity::query()->where('subject_type', Address::class)->count())->toBe(0);
})->with([
    'missing city' => ['city_id', null],
    'nonexistent city' => ['city_id', PHP_INT_MAX],
    'invalid city identifier' => ['city_id', 'abc'],
    'missing street' => ['street', null],
    'blank street' => ['street', '   '],
    'long street' => ['street', str_repeat('á', 256)],
    'missing number' => ['number', null],
    'blank number' => ['number', ''],
    'numeric number' => ['number', 123],
    'long number' => ['number', str_repeat('a', 256)],
    'missing neighborhood' => ['neighborhood', null],
    'blank neighborhood' => ['neighborhood', '   '],
    'long neighborhood' => ['neighborhood', str_repeat('ã', 256)],
]);

test('validates updates and leaves the persisted address unchanged after a failure', function () {
    $address = Address::factory()->create(['zip_code' => '97010000']);
    $original = $address->fresh()->getRawOriginal();

    expect(fn () => $address->update(['street' => '']))->toThrow(ValidationException::class);
    expect($address->fresh()->getRawOriginal())->toBe($original)
        ->and(Activity::forSubject($address)->count())->toBe(1);
});

test('copies persisted address data into independent rows with new identifiers and timestamps', function () {
    $this->freezeSecond();
    $address = Address::factory()->create(['zip_code' => null]);
    $original = $address->fresh()->getRawOriginal();
    $fields = $address->getFillable();
    $originalFields = $address->fresh()->only($fields);
    $address->street = 'Alteração ainda não salva';
    $address->city_id = City::factory()->create()->id;
    $this->travel(1)->minutes();

    $copy = (new CopyAddress)->handle($address);
    $secondCopy = (new CopyAddress)->handle($address);

    expect($copy->exists)->toBeTrue()
        ->and($copy->only($fields))->toBe($originalFields)
        ->and($secondCopy->only($fields))->toBe($originalFields)
        ->and($copy->id)->not->toBe($address->id)
        ->and($secondCopy->id)->not->toBe($copy->id)
        ->and($copy->created_at->gt($address->created_at))->toBeTrue()
        ->and($copy->created_at->equalTo($copy->updated_at))->toBeTrue()
        ->and($address->fresh()->getRawOriginal())->toBe($original)
        ->and($address->street)->toBe('Alteração ainda não salva')
        ->and(Address::count())->toBe(3);

    $address->fresh()->update(['street' => 'Novo endereço atual']);
    expect($copy->fresh()->only($fields))->toBe($originalFields)
        ->and($secondCopy->fresh()->only($fields))->toBe($originalFields);
});

test('rejects copying an address that has not been persisted', function () {
    $address = Address::factory()->make();
    expect(fn () => (new CopyAddress)->handle($address))->toThrow(InvalidArgumentException::class)
        ->and(Address::count())->toBe(0);
});

test('rejects copying an address that no longer exists', function () {
    $address = Address::factory()->create();
    Address::query()->whereKey($address->id)->delete();
    expect(fn () => (new CopyAddress)->handle($address))->toThrow(ModelNotFoundException::class)
        ->and(Address::count())->toBe(0);
});

test('logs address changes with account authorship and previous values', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $address = Address::factory()->create(['street' => 'Rua Original']);
    $creation = Activity::forSubject($address)->sole();

    expect($creation->event)->toBe('created')
        ->and($creation->causer_id)->toBe($user->id)
        ->and($creation->attribute_changes->get('attributes'))->toMatchArray(['street' => 'Rua Original']);

    $address->update(['street' => 'Rua Atualizada']);
    $update = Activity::forSubject($address)->where('event', 'updated')->sole();
    expect($update->causer_id)->toBe($user->id)
        ->and($update->attribute_changes->all())->toEqual([
            'attributes' => ['street' => 'Rua Atualizada'],
            'old' => ['street' => 'Rua Original'],
        ]);

    $address->save();
    $this->travel(1)->minutes();
    $address->touch();
    expect(Activity::forSubject($address)->count())->toBe(3);
});
