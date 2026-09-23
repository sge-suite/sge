<?php

use App\Models\Address;
use App\Models\Campus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

test('creates the campuses schema with exact PostgreSQL types and constraints', function () {
    expect(DB::getDriverName())->toBe('pgsql');
    $columns = collect(Schema::getColumns('campuses'))->keyBy('name');

    expect($columns->keys()->all())->toBe([
        'id',
        'name',
        'cnpj',
        'phone',
        'email',
        'address_id',
        'legal_representative_name',
        'legal_representative_position',
        'insurance_company_name',
        'insurance_policy_number',
        'deactivated_at',
        'deleted_at',
        'created_at',
        'updated_at',
    ]);

    foreach ([
        'id' => ['bigint', false],
        'name' => ['character varying(255)', false],
        'cnpj' => ['character(14)', true],
        'phone' => ['character varying(255)', true],
        'email' => ['character varying(255)', true],
        'address_id' => ['bigint', false],
        'legal_representative_name' => ['character varying(255)', true],
        'legal_representative_position' => ['character varying(255)', true],
        'insurance_company_name' => ['character varying(255)', true],
        'insurance_policy_number' => ['character varying(255)', true],
        'deactivated_at' => ['timestamp(0) without time zone', true],
        'deleted_at' => ['timestamp(0) without time zone', true],
        'created_at' => ['timestamp(0) without time zone', true],
        'updated_at' => ['timestamp(0) without time zone', true],
    ] as $name => [$type, $nullable]) {
        expect($columns->get($name))->toMatchArray(['type' => $type, 'nullable' => $nullable]);
    }

    $indexes = collect(Schema::getIndexes('campuses'));
    $foreignKeys = collect(Schema::getForeignKeys('campuses'));

    expect($indexes->firstWhere('primary', true))->toMatchArray([
        'columns' => ['id'], 'primary' => true, 'unique' => true,
    ])
        ->and($indexes->firstWhere('name', 'campuses_address_id_index'))->toMatchArray([
            'columns' => ['address_id'], 'unique' => false,
        ])
        ->and($indexes->firstWhere('name', 'campuses_deactivated_at_index'))->toMatchArray([
            'columns' => ['deactivated_at'], 'unique' => false,
        ])
        ->and($foreignKeys)->toHaveCount(1)
        ->and($foreignKeys->sole())->toMatchArray([
            'columns' => ['address_id'],
            'foreign_table' => 'addresses',
            'foreign_columns' => ['id'],
            'on_delete' => 'restrict',
        ]);
});

test('rolls back campuses and affiliations in dependency order on PostgreSQL', function () {
    $paths = glob(database_path('migrations/*_create_campuses_table.php'));
    expect($paths)->toHaveCount(1);
    $campusMigration = pathinfo($paths[0], PATHINFO_FILENAME);
    $steps = DB::table('migrations')->where('migration', '>=', $campusMigration)->count();

    $this->artisan('migrate:rollback', ['--step' => $steps, '--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('campuses'))->toBeFalse()
        ->and(Schema::hasTable('affiliations'))->toBeFalse()
        ->and(Schema::hasTable('addresses'))->toBeTrue()
        ->and(Schema::hasTable('user_personal_data'))->toBeTrue();

    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('campuses'))->toBeTrue()
        ->and(Schema::hasTable('affiliations'))->toBeTrue();
});

test('enforces the required current address and its foreign key in PostgreSQL', function () {
    $attributes = Campus::factory()->raw([
        'cnpj' => '04252011000110',
        'phone' => '55999999999',
        'address_id' => Address::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    unset($attributes['address_id']);
    expect(fn () => DB::transaction(fn () => Campus::query()->insert($attributes)))
        ->toThrow(QueryException::class);

    $attributes['address_id'] = PHP_INT_MAX;
    expect(fn () => DB::transaction(fn () => Campus::query()->insert($attributes)))
        ->toThrow(QueryException::class);
});

test('restricts deletion of the current address until the campus is force deleted', function () {
    $campus = Campus::factory()->create();
    $address = $campus->address()->sole();

    expect(fn () => DB::transaction(fn () => $address->delete()))->toThrow(QueryException::class);
    $this->assertModelExists($address);

    $campus->delete();
    expect(fn () => DB::transaction(fn () => $address->delete()))->toThrow(QueryException::class);

    $campus->forceDelete();
    $address->delete();
    $this->assertModelMissing($address);
});

test('creates campuses through factories, casts Brazilian contact data, and exposes address relationships', function () {
    $this->freezeSecond();
    $campus = Campus::factory()->withInsurance()->create([
        'name' => 'Campus Central',
        'cnpj' => '04.252.011/0001-10',
        'phone' => '(55) 99999-9999',
    ])->fresh()->load('address');
    $address = $campus->address;
    $address->load('campuses');

    expect($campus->cnpj)->toBe('04252011000110')
        ->and($campus->phone)->toBe('55999999999')
        ->and($campus->legal_representative_name)->not->toBeNull()
        ->and($campus->legal_representative_position)->toBe('Diretor(a) Geral')
        ->and($campus->insurance_company_name)->not->toBeNull()
        ->and($campus->insurance_policy_number)->not->toBeNull()
        ->and($campus->address->is($address))->toBeTrue()
        ->and($address->campuses->sole()->is($campus))->toBeTrue()
        ->and($campus->created_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($campus->updated_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($campus->created_at->micro)->toBe(0);
});

test('keeps insurance and legal representative data nullable without affiliation foreign keys', function () {
    $campus = Campus::factory()->create([
        'cnpj' => null,
        'phone' => null,
        'email' => '   ',
        'legal_representative_name' => '   ',
        'legal_representative_position' => '   ',
    ]);

    expect($campus->fresh()->only([
        'cnpj',
        'phone',
        'email',
        'legal_representative_name',
        'legal_representative_position',
        'insurance_company_name',
        'insurance_policy_number',
    ]))->toBe([
        'cnpj' => null,
        'phone' => null,
        'email' => null,
        'legal_representative_name' => null,
        'legal_representative_position' => null,
        'insurance_company_name' => null,
        'insurance_policy_number' => null,
    ])
        ->and(Schema::getForeignKeys('campuses'))->toHaveCount(1);
});

test('accepts an insurance policy number beyond the previous field limit', function () {
    $policyNumber = str_repeat('a', 101);
    $campus = Campus::factory()->create(['insurance_policy_number' => $policyNumber]);

    expect($campus->fresh()->insurance_policy_number)->toBe($policyNumber);
});

test('filters active campuses and soft deletes deactivated records independently', function () {
    $activeCampus = Campus::factory()->create();
    $deactivatedCampus = Campus::factory()->deactivated()->create();
    $deletedCampus = Campus::factory()->create();

    $deletedCampus->delete();

    expect(Campus::query()->active()->get()->sole()->is($activeCampus))->toBeTrue()
        ->and($deactivatedCampus->deactivated_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and(Campus::count())->toBe(2)
        ->and(Campus::withTrashed()->count())->toBe(3);

    $deletedCampus->restore();
    $this->assertModelExists($deletedCampus);
});

test('validates all campus fields before persisting', function (string $field, mixed $value) {
    expect(fn () => Campus::factory()->create([$field => $value]))
        ->toThrow(ValidationException::class)
        ->and(Campus::count())->toBe(0);
})->with([
    'missing name' => ['name', null],
    'blank name' => ['name', '   '],
    'numeric name' => ['name', 123],
    'long name' => ['name', str_repeat('á', 256)],
    'invalid email' => ['email', 'campus.example.test'],
    'missing address' => ['address_id', null],
    'invalid address identifier' => ['address_id', 'abc'],
    'nonexistent address' => ['address_id', PHP_INT_MAX],
    'long legal representative name' => ['legal_representative_name', str_repeat('á', 256)],
    'long legal representative position' => ['legal_representative_position', str_repeat('á', 256)],
    'long insurance company' => ['insurance_company_name', str_repeat('á', 256)],
]);

test('rejects invalid Brazilian CNPJ and telephone values before persisting', function (string $field, string $value) {
    expect(fn () => Campus::factory()->create([$field => $value]))
        ->toThrow(InvalidArgumentException::class)
        ->and(Campus::count())->toBe(0);
})->with([
    'invalid CNPJ' => ['cnpj', '04.252.011/0001-11'],
    'telephone without DDD' => ['phone', '999999999'],
]);

test('accepts a telephone with DDD supplied as digits only', function () {
    $campus = Campus::factory()->create(['phone' => '55999999999']);

    expect($campus->fresh()->phone)->toBe('55999999999');
});

test('logs creation and dirty campus updates with the authenticated causer', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $campus = Campus::factory()->create(['name' => 'Campus Original']);
    $creation = Activity::forSubject($campus)->sole();

    expect($creation->event)->toBe('created')
        ->and($creation->causer_id)->toBe($user->id)
        ->and($creation->attribute_changes->get('attributes'))->toMatchArray(['name' => 'Campus Original']);

    $campus->update(['name' => 'Campus Atualizado']);
    $update = Activity::forSubject($campus)->where('event', 'updated')->sole();
    expect($update->causer_id)->toBe($user->id)
        ->and($update->attribute_changes->all())->toBe([
            'attributes' => ['name' => 'Campus Atualizado'],
            'old' => ['name' => 'Campus Original'],
        ]);

    $campus->save();
    $this->travel(1)->minutes();
    $campus->touch();
    expect(Activity::forSubject($campus)->count())->toBe(2);
});
