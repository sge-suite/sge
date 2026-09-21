<?php

use App\Models\Address;
use App\Models\User;
use App\Models\UserPersonalData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

test('creates the user personal data schema with exact PostgreSQL types and constraints', function () {
    expect(DB::getDriverName())->toBe('pgsql');
    $columns = collect(Schema::getColumns('user_personal_data'))->keyBy('name');

    expect($columns->keys()->all())->toBe([
        'id', 'user_id', 'rg', 'rg_issuer', 'rg_issue_date', 'birth_date', 'phone', 'address_id',
        'emancipation_verified_at', 'created_at', 'updated_at',
    ]);

    foreach ([
        'id' => ['bigint', false],
        'user_id' => ['bigint', false],
        'rg' => ['character varying(255)', true],
        'rg_issuer' => ['character varying(255)', true],
        'rg_issue_date' => ['date', true],
        'birth_date' => ['date', true],
        'phone' => ['character varying(255)', true],
        'address_id' => ['bigint', true],
        'emancipation_verified_at' => ['timestamp(0) without time zone', true],
        'created_at' => ['timestamp(0) without time zone', true],
        'updated_at' => ['timestamp(0) without time zone', true],
    ] as $name => [$type, $nullable]) {
        expect($columns->get($name))->toMatchArray(['type' => $type, 'nullable' => $nullable]);
    }

    $indexes = collect(Schema::getIndexes('user_personal_data'));
    $foreignKeys = collect(Schema::getForeignKeys('user_personal_data'));

    expect($indexes->firstWhere('primary', true))->toMatchArray([
        'columns' => ['id'], 'primary' => true, 'unique' => true,
    ])
        ->and($indexes->firstWhere('name', 'user_personal_data_user_id_unique'))->toMatchArray([
            'columns' => ['user_id'], 'unique' => true,
        ])
        ->and($indexes->firstWhere('name', 'user_personal_data_address_id_index'))->toMatchArray([
            'columns' => ['address_id'], 'unique' => false,
        ])
        ->and($foreignKeys->firstWhere('columns', ['user_id']))->toMatchArray([
            'columns' => ['user_id'],
            'foreign_table' => 'users',
            'foreign_columns' => ['id'],
            'on_delete' => 'cascade',
        ])
        ->and($foreignKeys->firstWhere('columns', ['address_id']))->toMatchArray([
            'columns' => ['address_id'],
            'foreign_table' => 'addresses',
            'foreign_columns' => ['id'],
            'on_delete' => 'restrict',
        ]);
});

test('rolls back and reapplies migrations from user personal data onwards on PostgreSQL', function () {
    $paths = glob(database_path('migrations/*_create_user_personal_data_table.php'));
    expect($paths)->toHaveCount(1);
    $personalDataMigration = pathinfo($paths[0], PATHINFO_FILENAME);
    $steps = DB::table('migrations')->where('migration', '>=', $personalDataMigration)->count();

    expect($steps)->toBeGreaterThan(0);
    $this->artisan('migrate:rollback', ['--step' => $steps, '--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('user_personal_data'))->toBeFalse()
        ->and(Schema::hasTable('campuses'))->toBeFalse()
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('addresses'))->toBeTrue();

    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('user_personal_data'))->toBeTrue()
        ->and(Schema::hasTable('campuses'))->toBeTrue();
});

test('enforces the required user, unique profile, and foreign keys in PostgreSQL', function () {
    $user = User::factory()->create();
    $personalData = UserPersonalData::factory()->for($user)->create();
    $attributes = UserPersonalData::factory()->raw([
        'user_id' => $user->id,
        'address_id' => Address::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::transaction(fn () => UserPersonalData::query()->insert($attributes)))
        ->toThrow(QueryException::class);

    $attributes['user_id'] = PHP_INT_MAX;
    expect(fn () => DB::transaction(fn () => UserPersonalData::query()->insert($attributes)))
        ->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => UserPersonalData::query()->insert([
        'address_id' => $personalData->address_id,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});

test('cascades a profile deletion with its user and restricts deleting its current address', function () {
    $personalData = UserPersonalData::factory()->create();
    $user = $personalData->user()->sole();
    $address = $personalData->address()->sole();

    expect(fn () => DB::transaction(fn () => $address->delete()))->toThrow(QueryException::class);
    $this->assertModelExists($address);

    $user->delete();
    $this->assertModelMissing($personalData);
});

test('keeps personal data optional and preserves CPF on the user account', function () {
    $userWithoutPersonalData = User::factory()->create();
    $user = User::factory()->create(['cpf' => '529.982.247-25']);
    $personalData = UserPersonalData::factory()->withoutOptionalData()->for($user)->create();

    expect($userWithoutPersonalData->personalData)->toBeNull()
        ->and($user->fresh()->cpf)->toBe('52998224725')
        ->and($user->personalData->is($personalData))->toBeTrue()
        ->and($personalData->fresh()->only([
            'rg', 'rg_issuer', 'rg_issue_date', 'birth_date', 'phone', 'address_id',
        ]))->toBe([
            'rg' => null,
            'rg_issuer' => null,
            'rg_issue_date' => null,
            'birth_date' => null,
            'phone' => null,
            'address_id' => null,
        ])
        ->and(Schema::hasColumn('user_personal_data', 'cpf'))->toBeFalse()
        ->and(Schema::hasColumn('user_personal_data', 'emancipation_verified_by_affiliation_id'))->toBeFalse();
});

test('casts dates, exposes relationships, and keeps personal fields hidden from serialization', function () {
    $this->freezeSecond();
    $personalData = UserPersonalData::factory()->create([
        'rg_issue_date' => '2010-05-14',
        'birth_date' => '1992-03-10',
        'emancipation_verified_at' => now(),
    ])->fresh()->load(['user', 'address']);

    expect($personalData->rg_issue_date->toDateString())->toBe('2010-05-14')
        ->and($personalData->birth_date->toDateString())->toBe('1992-03-10')
        ->and($personalData->emancipation_verified_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($personalData->user->personalData->is($personalData))->toBeTrue()
        ->and($personalData->address->is($personalData->address()->sole()))->toBeTrue()
        ->and($personalData->toArray())->not->toHaveKeys([
            'rg', 'rg_issuer', 'rg_issue_date', 'birth_date', 'phone', 'emancipation_verified_at',
        ]);
});

test('normalizes telephone numbers to digits and nullifies blank optional values', function () {
    $personalData = UserPersonalData::factory()->create([
        'phone' => '(55) 99999-9999',
        'rg' => '   ',
        'rg_issuer' => '   ',
        'rg_issue_date' => '',
        'birth_date' => '',
    ]);

    expect($personalData->fresh()->phone)->toBe('55999999999')
        ->and($personalData->rg)->toBeNull()
        ->and($personalData->rg_issuer)->toBeNull()
        ->and($personalData->rg_issue_date)->toBeNull()
        ->and($personalData->birth_date)->toBeNull();
});

test('rejects partial RG details, invalid dates, and invalid addresses', function (array $attributes) {
    expect(fn () => UserPersonalData::factory()->create($attributes))
        ->toThrow(ValidationException::class);
})->with([
    'RG without issuer and issue date' => [
        ['rg' => '12.345.678-9', 'rg_issuer' => null, 'rg_issue_date' => null],
    ],
    'issuer without RG and issue date' => [
        ['rg' => null, 'rg_issuer' => 'SSP/RS', 'rg_issue_date' => null],
    ],
    'issue date without RG and issuer' => [
        ['rg' => null, 'rg_issuer' => null, 'rg_issue_date' => '2010-05-14'],
    ],
    'future birth date' => [
        ['birth_date' => now()->addDay()->toDateString()],
    ],
    'nonexistent address' => [
        ['address_id' => PHP_INT_MAX],
    ],
]);
