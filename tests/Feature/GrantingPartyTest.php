<?php

use App\Enums\PartyDocumentType;
use App\Models\Address;
use App\Models\GrantingParty;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

test('creates the granting parties schema with the contracted PostgreSQL types', function () {
    expect(DB::getDriverName())->toBe('pgsql');
    $columns = collect(Schema::getColumns('granting_parties'))->keyBy('name');

    expect($columns->keys()->all())->toBe([
        'id', 'document_type', 'document_number', 'name', 'address_id',
        'representative_name', 'representative_role', 'phone', 'email',
        'field_of_activity', 'professional_council', 'council_registration_number',
        'credentialing_process_number', 'created_at', 'updated_at', 'deleted_at',
    ]);

    foreach ([
        'id' => ['bigint', false],
        'document_type' => ['character varying(255)', false],
        'document_number' => ['character varying(255)', false],
        'name' => ['character varying(255)', false],
        'address_id' => ['bigint', false],
        'representative_name' => ['character varying(255)', false],
        'representative_role' => ['character varying(255)', false],
        'phone' => ['character varying(255)', true],
        'email' => ['character varying(255)', true],
        'field_of_activity' => ['character varying(255)', false],
        'professional_council' => ['character varying(255)', true],
        'council_registration_number' => ['character varying(255)', true],
        'credentialing_process_number' => ['character varying(255)', true],
        'created_at' => ['timestamp(0) without time zone', true],
        'updated_at' => ['timestamp(0) without time zone', true],
        'deleted_at' => ['timestamp(0) without time zone', true],
    ] as $name => [$type, $nullable]) {
        expect($columns->get($name))->toMatchArray(['type' => $type, 'nullable' => $nullable]);
    }

    expect(Schema::getIndexes('granting_parties'))->toHaveCount(1)
        ->and(Schema::getIndexes('granting_parties')[0])->toMatchArray([
            'columns' => ['id'], 'primary' => true, 'unique' => true,
        ])
        ->and(Schema::getForeignKeys('granting_parties'))->toHaveCount(1)
        ->and(Schema::getForeignKeys('granting_parties')[0])->toMatchArray([
            'columns' => ['address_id'], 'foreign_table' => 'addresses',
            'foreign_columns' => ['id'], 'on_delete' => 'restrict',
        ])
        ->and(DB::select("SELECT conname FROM pg_constraint WHERE conrelid = 'granting_parties'::regclass AND contype = 'c'"))->toBeEmpty();
});

test('rolls back and reapplies the granting parties migration', function () {
    $path = glob(database_path('migrations/*_create_granting_parties_table.php'))[0];
    $requestPath = glob(database_path('migrations/*_create_granting_party_registration_requests_table.php'))[0];
    $batch = DB::table('migrations')
        ->where('migration', pathinfo($path, PATHINFO_FILENAME))
        ->value('batch');

    $this->artisan('migrate:rollback', [
        '--path' => [$requestPath],
        '--realpath' => true,
        '--batch' => $batch,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $this->artisan('migrate:rollback', [
        '--path' => [$path],
        '--realpath' => true,
        '--batch' => $batch,
        '--no-interaction' => true,
    ])->assertSuccessful();
    expect(Schema::hasTable('granting_parties'))->toBeFalse()
        ->and(Schema::hasTable('addresses'))->toBeTrue();

    $this->artisan('migrate', [
        '--path' => [$path],
        '--realpath' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();
    $this->artisan('migrate', [
        '--path' => [$requestPath],
        '--realpath' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();
    expect(Schema::hasTable('granting_parties'))->toBeTrue()
        ->and(Schema::hasTable('granting_party_registration_requests'))->toBeTrue();
});

test('requires identification, address, representative and activity at the database level', function (string $field) {
    $address = Address::factory()->create();
    $attributes = [
        'document_type' => 'cnpj',
        'document_number' => '04252011000110',
        'name' => 'Unidade Central',
        'address_id' => $address->id,
        'representative_name' => 'Maria Silva',
        'representative_role' => 'Diretora',
        'field_of_activity' => 'Saúde',
        'created_at' => now(),
        'updated_at' => now(),
    ];
    unset($attributes[$field]);

    expect(fn () => DB::transaction(fn () => GrantingParty::query()->insert($attributes)))
        ->toThrow(QueryException::class);
})->with([
    'document_type', 'document_number', 'name', 'address_id',
    'representative_name', 'representative_role', 'field_of_activity',
]);

test('casts and normalizes both document types and allows distinct units with the same CNPJ', function () {
    $first = GrantingParty::factory()->cnpj()->unit('Organização - Unidade Centro')->create();
    $second = GrantingParty::factory()->cnpj()->unit('Organização - Unidade Norte')->create();
    $person = GrantingParty::factory()->cpf()->create();

    expect($first->fresh()->document_type)->toBe(PartyDocumentType::CNPJ)
        ->and($first->fresh()->document_number)->toBe('04252011000110')
        ->and($second->fresh()->document_number)->toBe($first->fresh()->document_number)
        ->and($second->name)->not->toBe($first->name)
        ->and($person->fresh()->document_type)->toBe(PartyDocumentType::CPF)
        ->and($person->fresh()->document_number)->toBe('52998224725');
});

test('rejects missing required identification, address, representative and activity', function (string $field, mixed $value) {
    expect(fn () => GrantingParty::factory()->create([$field => $value]))
        ->toThrow(ValidationException::class);
})->with([
    'missing type' => ['document_type', null],
    'missing document' => ['document_number', null],
    'blank document' => ['document_number', '   '],
    'missing name' => ['name', null],
    'blank name' => ['name', '   '],
    'long name' => ['name', str_repeat('á', 256)],
    'missing address' => ['address_id', null],
    'missing representative name' => ['representative_name', null],
    'blank representative name' => ['representative_name', '   '],
    'missing representative role' => ['representative_role', null],
    'blank representative role' => ['representative_role', '   '],
    'missing activity' => ['field_of_activity', null],
    'blank activity' => ['field_of_activity', '   '],
]);

test('rejects a document type outside the enum when assigning it', function () {
    expect(fn () => GrantingParty::factory()->create(['document_type' => 'rg']))
        ->toThrow(ValueError::class);
});

test('rejects invalid or mismatched CPF and CNPJ values', function (string $type, string $number) {
    expect(fn () => GrantingParty::factory()->create([
        'document_type' => $type,
        'document_number' => $number,
    ]))->toThrow(InvalidArgumentException::class);
})->with([
    'invalid CPF' => ['cpf', '529.982.247-26'],
    'invalid CNPJ' => ['cnpj', '04.252.011/0001-11'],
    'CPF with CNPJ type' => ['cnpj', '529.982.247-25'],
    'CNPJ with CPF type' => ['cpf', '04.252.011/0001-10'],
]);

test('rejects changing the document type without a matching document number', function () {
    $party = GrantingParty::factory()->cpf()->create();

    expect(fn () => $party->update(['document_type' => PartyDocumentType::CNPJ]))
        ->toThrow(InvalidArgumentException::class);

    expect($party->fresh()->document_type)->toBe(PartyDocumentType::CPF)
        ->and($party->fresh()->document_number)->toBe('52998224725');
});

test('persists representative, activity, council and credentialing details', function () {
    $party = GrantingParty::factory()->create([
        'representative_name' => 'Maria Silva',
        'representative_role' => 'Diretora',
        'email' => 'maria@example.test',
        'field_of_activity' => 'Saúde',
        'professional_council' => 'COREN',
        'council_registration_number' => '12345',
        'credentialing_process_number' => '2026/123',
    ])->fresh();

    expect($party->only([
        'representative_name', 'representative_role', 'email', 'field_of_activity',
        'professional_council', 'council_registration_number', 'credentialing_process_number',
    ]))->toBe([
        'representative_name' => 'Maria Silva',
        'representative_role' => 'Diretora',
        'email' => 'maria@example.test',
        'field_of_activity' => 'Saúde',
        'professional_council' => 'COREN',
        'council_registration_number' => '12345',
        'credentialing_process_number' => '2026/123',
    ]);
});

test('uses the existing phone cast for fixed and mobile numbers and blank values', function () {
    $party = GrantingParty::factory()->create(['phone' => '(55) 9999-9999']);
    expect($party->fresh()->phone)->toBe('5599999999');

    $party->update(['phone' => '(55) 99999-9999']);
    expect($party->fresh()->phone)->toBe('55999999999');

    $party->update(['phone' => '   ']);
    expect($party->fresh()->phone)->toBeNull();

    $party->update(['phone' => '55999999999']);
    expect($party->fresh()->phone)->toBe('55999999999');

    expect(fn () => $party->update(['phone' => '559999999']))
        ->toThrow(InvalidArgumentException::class);
});

test('requires an address and restricts deletion while referenced', function () {
    $address = Address::factory()->create();
    $party = GrantingParty::factory()->for($address)->create();
    expect($party->fresh()->address->is($address))->toBeTrue();

    expect(fn () => DB::transaction(fn () => $address->delete()))->toThrow(QueryException::class);
    $party->delete();
    expect(fn () => DB::transaction(fn () => $address->delete()))->toThrow(QueryException::class);

    $party->restore();
    $replacement = Address::factory()->create();
    $party->update(['address_id' => $replacement->id]);
    $address->delete();
    $this->assertModelMissing($address);
    expect($party->fresh()->address->is($replacement))->toBeTrue();
});

test('accepts an address without a ZIP code', function () {
    $address = Address::factory()->create(['zip_code' => null]);
    $party = GrantingParty::factory()->for($address)->create();

    expect($party->fresh()->address->zip_code)->toBeNull();
});

test('validates details and address references before saving', function (string $field, mixed $value) {
    expect(fn () => GrantingParty::factory()->create([$field => $value]))
        ->toThrow(ValidationException::class);
})->with([
    'unknown address' => ['address_id', PHP_INT_MAX],
    'invalid email' => ['email', 'invalid'],
    'long role' => ['representative_role', str_repeat('á', 256)],
    'long council' => ['professional_council', str_repeat('á', 121)],
    'long registration' => ['council_registration_number', str_repeat('x', 65)],
    'long process' => ['credentialing_process_number', str_repeat('x', 101)],
]);

test('soft deletes records and logs cadastral changes', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $party = GrantingParty::factory()->create();
    $party->update(['representative_name' => 'Maria Silva']);

    expect(Activity::forSubject($party)->count())->toBe(2)
        ->and(Activity::forSubject($party)->where('event', 'updated')->sole()->causer_id)->toBe($user->id);

    $party->delete();
    expect(GrantingParty::query()->whereKey($party->id)->exists())->toBeFalse()
        ->and(GrantingParty::withTrashed()->whereKey($party->id)->exists())->toBeTrue();

    $party->restore();
    $this->assertModelExists($party);
});
