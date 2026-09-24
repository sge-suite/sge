<?php

use App\Enums\BrazilianState;
use App\Enums\PartyDocumentType;
use App\Enums\RegistrationRequestStatus;
use App\Models\GrantingParty;
use App\Models\GrantingPartyRegistrationRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

test('creates the granting party registration request schema with PostgreSQL types and restricted foreign key', function () {
    expect(DB::getDriverName())->toBe('pgsql');

    $columns = collect(Schema::getColumns('granting_party_registration_requests'))->keyBy('name');

    expect($columns->keys()->all())->toBe([
        'id', 'document_type', 'document_number', 'name', 'street', 'number',
        'neighborhood', 'city', 'uf', 'zip_code', 'representative_name',
        'representative_role', 'phone', 'email', 'field_of_activity',
        'professional_council', 'council_registration_number',
        'credentialing_process_number', 'status', 'granting_party_id',
        'reviewed_at', 'decision_reason', 'created_at', 'updated_at',
    ]);

    foreach ([
        'id' => ['bigint', false],
        'document_type' => ['character varying(255)', true],
        'document_number' => ['character varying(255)', true],
        'name' => ['character varying(255)', true],
        'street' => ['character varying(255)', true],
        'number' => ['character varying(255)', true],
        'neighborhood' => ['character varying(255)', true],
        'city' => ['character varying(255)', true],
        'uf' => ['character varying(255)', true],
        'zip_code' => ['character varying(255)', true],
        'representative_name' => ['character varying(255)', true],
        'representative_role' => ['character varying(255)', true],
        'phone' => ['character varying(255)', true],
        'email' => ['character varying(255)', true],
        'field_of_activity' => ['character varying(255)', true],
        'professional_council' => ['character varying(255)', true],
        'council_registration_number' => ['character varying(255)', true],
        'credentialing_process_number' => ['character varying(255)', true],
        'status' => ['character varying(255)', false],
        'granting_party_id' => ['bigint', true],
        'reviewed_at' => ['timestamp(0) without time zone', true],
        'decision_reason' => ['text', true],
        'created_at' => ['timestamp(0) without time zone', true],
        'updated_at' => ['timestamp(0) without time zone', true],
    ] as $name => [$type, $nullable]) {
        expect($columns->get($name))->toMatchArray(['type' => $type, 'nullable' => $nullable]);
    }

    $indexes = collect(Schema::getIndexes('granting_party_registration_requests'));
    $foreignKeys = collect(Schema::getForeignKeys('granting_party_registration_requests'));

    expect($indexes)->toHaveCount(1)
        ->and($indexes->first())->toMatchArray(['columns' => ['id'], 'primary' => true, 'unique' => true])
        ->and($foreignKeys)->toHaveCount(1)
        ->and($foreignKeys->firstWhere('columns', ['granting_party_id']))->toMatchArray([
            'foreign_table' => 'granting_parties', 'foreign_columns' => ['id'], 'on_delete' => 'restrict',
        ])
        ->and(Schema::hasColumn('granting_party_registration_requests', 'deleted_at'))->toBeFalse()
        ->and(DB::select("SELECT conname FROM pg_constraint WHERE conrelid = 'granting_party_registration_requests'::regclass AND contype = 'c'"))->toBeEmpty();
});

test('rolls back and reapplies the granting party registration request migration', function () {
    $path = glob(database_path('migrations/*_create_granting_party_registration_requests_table.php'))[0];
    $internshipRequestPath = glob(database_path('migrations/*_create_internship_requests_table.php'))[0];
    $batch = DB::table('migrations')
        ->where('migration', pathinfo($path, PATHINFO_FILENAME))
        ->value('batch');

    $dependentPaths = array_map(
        fn (string $name): string => glob(database_path("migrations/*_create_{$name}_table.php"))[0],
        ['internship_request_corrections', 'emancipation_evidences'],
    );

    foreach ($dependentPaths as $dependentPath) {
        $this->artisan('migrate:rollback', [
            '--path' => [$dependentPath], '--realpath' => true, '--batch' => $batch, '--no-interaction' => true,
        ])->assertSuccessful();
    }

    $this->artisan('migrate:rollback', [
        '--path' => [$internshipRequestPath], '--realpath' => true, '--batch' => $batch, '--no-interaction' => true,
    ])->assertSuccessful();

    $this->artisan('migrate:rollback', [
        '--path' => [$path],
        '--realpath' => true,
        '--batch' => $batch,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(Schema::hasTable('granting_party_registration_requests'))->toBeFalse()
        ->and(Schema::hasTable('granting_parties'))->toBeTrue();

    $this->artisan('migrate', [
        '--path' => [$path],
        '--realpath' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $this->artisan('migrate', [
        '--path' => [$internshipRequestPath], '--realpath' => true, '--no-interaction' => true,
    ])->assertSuccessful();

    foreach (array_reverse($dependentPaths) as $dependentPath) {
        $this->artisan('migrate', [
            '--path' => [$dependentPath], '--realpath' => true, '--no-interaction' => true,
        ])->assertSuccessful();
    }

    expect(Schema::hasTable('granting_party_registration_requests'))->toBeTrue();
});

test('allows a draft without cadastro fields', function () {
    $request = GrantingPartyRegistrationRequest::factory()->create([
        'document_type' => null,
        'document_number' => null,
        'name' => null,
        'street' => null,
        'number' => null,
        'neighborhood' => null,
        'city' => null,
        'uf' => null,
        'zip_code' => null,
        'representative_name' => null,
        'representative_role' => null,
        'field_of_activity' => null,
    ])->fresh();

    expect($request->status)->toBe(RegistrationRequestStatus::Draft)
        ->and($request->document_type)->toBeNull()
        ->and($request->document_number)->toBeNull()
        ->and($request->name)->toBeNull()
        ->and($request->street)->toBeNull()
        ->and($request->uf)->toBeNull()
        ->and($request->zip_code)->toBeNull()
        ->and($request->field_of_activity)->toBeNull();
});

test('requires all cadastro fields in every non-draft status', function () {
    foreach (RegistrationRequestStatus::cases() as $status) {
        if ($status === RegistrationRequestStatus::Draft) {
            continue;
        }

        expect(fn () => GrantingPartyRegistrationRequest::factory()->create([
            'status' => $status,
            'document_type' => null,
            'document_number' => null,
            'name' => null,
            'street' => null,
            'number' => null,
            'neighborhood' => null,
            'city' => null,
            'uf' => null,
            'zip_code' => null,
            'representative_name' => null,
            'representative_role' => null,
            'field_of_activity' => null,
        ]))->toThrow(ValidationException::class);
    }
});

test('requires each non-draft cadastro field', function (string $field) {
    expect(fn () => GrantingPartyRegistrationRequest::factory()->create([
        'status' => RegistrationRequestStatus::Submitted,
        $field => null,
    ]))->toThrow(ValidationException::class);
})->with([
    'document_type', 'document_number', 'name', 'street', 'number',
    'neighborhood', 'city', 'uf', 'zip_code', 'representative_name',
    'representative_role', 'field_of_activity',
]);

test('normalizes CPF or CNPJ according to document type and casts UF, phone, status and review time', function (PartyDocumentType $type, string $document, string $normalized) {
    $request = GrantingPartyRegistrationRequest::factory()->create([
        'document_type' => $type,
        'document_number' => $document,
        'uf' => BrazilianState::RioGrandeDoSul,
        'zip_code' => '97010-100',
        'phone' => '(55) 99999-9999',
        'reviewed_at' => '2026-09-23 12:30:00',
    ])->fresh();

    expect($request->document_type)->toBe($type)
        ->and($request->document_number)->toBe($normalized)
        ->and($request->uf)->toBe(BrazilianState::RioGrandeDoSul)
        ->and($request->zip_code)->toBe('97010100')
        ->and($request->phone)->toBe('55999999999')
        ->and($request->status)->toBe(RegistrationRequestStatus::Draft)
        ->and($request->reviewed_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($request->toArray())->not->toHaveKey('document_number');
})->with([
    'CPF' => [PartyDocumentType::CPF, '529.982.247-25', '52998224725'],
    'CNPJ' => [PartyDocumentType::CNPJ, '04.252.011/0001-10', '04252011000110'],
]);

test('rejects invalid documents, contact and address formats', function (array $overrides, string $exception) {
    expect(fn () => GrantingPartyRegistrationRequest::factory()->create($overrides))->toThrow($exception);
})->with([
    'invalid CPF' => [['document_type' => PartyDocumentType::CPF, 'document_number' => '529.982.247-26'], InvalidArgumentException::class],
    'invalid CNPJ' => [['document_type' => PartyDocumentType::CNPJ, 'document_number' => '04.252.011/0001-11'], InvalidArgumentException::class],
    'type and number mismatch' => [['document_type' => PartyDocumentType::CPF, 'document_number' => '04.252.011/0001-10'], InvalidArgumentException::class],
    'invalid phone' => [['phone' => '559999999'], InvalidArgumentException::class],
    'invalid email' => [['email' => 'invalid-email'], ValidationException::class],
    'invalid CEP' => [['zip_code' => '97010-10'], ValidationException::class],
    'invalid UF' => [['uf' => 'XX'], ValueError::class],
    'invalid document type' => [['document_type' => 'rg'], ValueError::class],
]);

test('requires document type when a draft has a document number', function () {
    expect(fn () => GrantingPartyRegistrationRequest::factory()->create([
        'document_type' => null,
    ]))->toThrow(ValidationException::class);
});

test('persists each non-draft status with its decision fields', function (RegistrationRequestStatus $status) {
    $attributes = ['status' => $status];

    if ($status === RegistrationRequestStatus::Approved) {
        $attributes['granting_party_id'] = GrantingParty::factory()->create()->id;
    }

    if (in_array($status, [RegistrationRequestStatus::Rejected, RegistrationRequestStatus::Cancelled], true)) {
        $attributes['decision_reason'] = 'Dados cadastrais conferidos.';
    }

    $request = GrantingPartyRegistrationRequest::factory()->create($attributes)->fresh();

    expect($request->status)->toBe($status)
        ->and($request->name)->not->toBeEmpty()
        ->and($request->document_number)->not->toBeEmpty();
})->with([
    'submitted' => RegistrationRequestStatus::Submitted,
    'under review' => RegistrationRequestStatus::UnderReview,
    'approved' => RegistrationRequestStatus::Approved,
    'rejected' => RegistrationRequestStatus::Rejected,
    'cancelled' => RegistrationRequestStatus::Cancelled,
]);

test('requires a granting party for approval and exposes both relationships', function () {
    expect(fn () => GrantingPartyRegistrationRequest::factory()->create([
        'status' => RegistrationRequestStatus::Approved,
    ]))->toThrow(ValidationException::class);

    $party = GrantingParty::factory()->create();
    $request = GrantingPartyRegistrationRequest::factory()->create([
        'status' => RegistrationRequestStatus::Approved,
        'granting_party_id' => $party->id,
    ])->fresh();

    expect($request->grantingParty->is($party))->toBeTrue()
        ->and($party->grantingPartyRegistrationRequests()->whereKey($request->id)->exists())->toBeTrue();
});

test('rejects a nonexistent resulting granting party in the Model and foreign key', function () {
    expect(fn () => GrantingPartyRegistrationRequest::factory()->create([
        'status' => RegistrationRequestStatus::Approved,
        'granting_party_id' => 999999,
    ]))->toThrow(ValidationException::class);

    expect(fn () => DB::table('granting_party_registration_requests')->insert([
        'status' => RegistrationRequestStatus::Draft->value,
        'granting_party_id' => 999999,
    ]))->toThrow(QueryException::class);
});

test('requires a reason for rejected and cancelled decisions', function (RegistrationRequestStatus $status) {
    expect(fn () => GrantingPartyRegistrationRequest::factory()->create([
        'status' => $status,
        'decision_reason' => '   ',
    ]))->toThrow(ValidationException::class);
})->with([
    'rejected' => RegistrationRequestStatus::Rejected,
    'cancelled' => RegistrationRequestStatus::Cancelled,
]);

test('preserves optional details and trims the reason', function () {
    $request = GrantingPartyRegistrationRequest::factory()->create([
        'status' => RegistrationRequestStatus::Rejected,
        'phone' => '(55) 99999-9999',
        'email' => 'contato@example.com',
        'professional_council' => 'Conselho Regional',
        'council_registration_number' => '12345',
        'credentialing_process_number' => 'Processo 99',
        'decision_reason' => '  Documento pendente.  ',
    ])->fresh();

    expect($request->phone)->toBe('55999999999')
        ->and($request->email)->toBe('contato@example.com')
        ->and($request->professional_council)->toBe('Conselho Regional')
        ->and($request->council_registration_number)->toBe('12345')
        ->and($request->credentialing_process_number)->toBe('Processo 99')
        ->and($request->decision_reason)->toBe('Documento pendente.');
});

test('allows distinct units with the same CNPJ', function () {
    $first = GrantingPartyRegistrationRequest::factory()->create(['name' => 'Unidade A']);
    $second = GrantingPartyRegistrationRequest::factory()->create(['name' => 'Unidade B']);

    expect($first->document_number)->toBe($second->document_number)
        ->and($first->id)->not->toBe($second->id);
});

test('restricts physical deletion of the resulting granting party and request', function () {
    $party = GrantingParty::factory()->create();
    $request = GrantingPartyRegistrationRequest::factory()->create([
        'status' => RegistrationRequestStatus::Approved,
        'granting_party_id' => $party->id,
        'reviewed_at' => now(),
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('granting_parties')->where('id', $party->id)->delete()))->toThrow(QueryException::class);
    $this->assertModelExists($party);

    expect(fn () => $request->delete())->toThrow(ValidationException::class);
    $this->assertModelExists($request);
});
