<?php

use App\Enums\RegistrationRequestStatus;
use App\Models\Affiliation;
use App\Models\SupervisorRegistrationRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

test('creates the supervisor registration request schema with PostgreSQL types and restricted foreign keys', function () {
    expect(DB::getDriverName())->toBe('pgsql');

    $columns = collect(Schema::getColumns('supervisor_registration_requests'))->keyBy('name');

    expect($columns->keys()->all())->toBe([
        'id', 'name', 'cpf', 'phone', 'email', 'job_role',
        'qualification', 'training', 'professional_experience',
        'status', 'supervisor_affiliation_id', 'reviewed_at',
        'decision_reason', 'created_at', 'updated_at',
    ]);

    foreach ([
        'id' => ['bigint', false],
        'name' => ['character varying(255)', true],
        'cpf' => ['character varying(255)', true],
        'phone' => ['character varying(255)', true],
        'email' => ['character varying(255)', true],
        'job_role' => ['character varying(255)', true],
        'qualification' => ['character varying(255)', true],
        'training' => ['text', true],
        'professional_experience' => ['text', true],
        'status' => ['character varying(255)', false],
        'supervisor_affiliation_id' => ['bigint', true],
        'reviewed_at' => ['timestamp(0) without time zone', true],
        'decision_reason' => ['text', true],
        'created_at' => ['timestamp(0) without time zone', true],
        'updated_at' => ['timestamp(0) without time zone', true],
    ] as $name => [$type, $nullable]) {
        expect($columns->get($name))->toMatchArray(['type' => $type, 'nullable' => $nullable]);
    }

    $indexes = collect(Schema::getIndexes('supervisor_registration_requests'));
    $foreignKeys = collect(Schema::getForeignKeys('supervisor_registration_requests'));

    expect($indexes)->toHaveCount(1)
        ->and($indexes->first())->toMatchArray(['columns' => ['id'], 'primary' => true, 'unique' => true])
        ->and($foreignKeys)->toHaveCount(1)
        ->and($foreignKeys->firstWhere('columns', ['supervisor_affiliation_id']))->toMatchArray([
            'foreign_table' => 'affiliations', 'foreign_columns' => ['id'], 'on_delete' => 'restrict',
        ])
        ->and(Schema::hasColumn('supervisor_registration_requests', 'deleted_at'))->toBeFalse()
        ->and(DB::select("SELECT conname FROM pg_constraint WHERE conrelid = 'supervisor_registration_requests'::regclass AND contype = 'c'"))->toBeEmpty();
});

test('rolls back and reapplies the supervisor registration request migration', function () {
    $this->artisan('migrate:rollback', ['--step' => 1, '--no-interaction' => true])->assertSuccessful();

    expect(Schema::hasTable('supervisor_registration_requests'))->toBeFalse()
        ->and(Schema::hasTable('affiliations'))->toBeTrue();

    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();

    expect(Schema::hasTable('supervisor_registration_requests'))->toBeTrue();
});

test('allows draft requests without the non-draft fields', function () {
    $request = SupervisorRegistrationRequest::factory()->create([
        'name' => null,
        'cpf' => null,
        'phone' => null,
        'email' => null,
        'job_role' => null,
        'qualification' => null,
    ])->fresh();

    expect($request->status)->toBe(RegistrationRequestStatus::Draft)
        ->and($request->name)->toBeNull()
        ->and($request->cpf)->toBeNull()
        ->and($request->phone)->toBeNull()
        ->and($request->email)->toBeNull()
        ->and($request->job_role)->toBeNull()
        ->and($request->qualification)->toBeNull()
        ->and($request->training)->toBeNull()
        ->and($request->professional_experience)->toBeNull();
});

test('requires all cadastro fields in every non-draft status', function () {
    foreach (RegistrationRequestStatus::cases() as $status) {
        if ($status === RegistrationRequestStatus::Draft) {
            continue;
        }

        expect(fn () => SupervisorRegistrationRequest::factory()->create([
            'status' => $status,
            'name' => null,
            'cpf' => null,
            'phone' => null,
            'email' => null,
            'job_role' => null,
            'qualification' => null,
        ]))->toThrow(ValidationException::class);
    }
});

test('requires each non-draft cadastro field', function (string $field) {
    expect(fn () => SupervisorRegistrationRequest::factory()->create([
        'status' => RegistrationRequestStatus::Submitted,
        $field => null,
    ]))->toThrow(ValidationException::class);
})->with(['name', 'cpf', 'phone', 'email', 'job_role', 'qualification']);

test('validates cpf and contact data and normalizes phone through the existing cast', function (array $overrides, string $exception) {
    expect(fn () => SupervisorRegistrationRequest::factory()->create($overrides))->toThrow($exception);
})->with([
    'invalid email' => [['email' => 'not-an-email'], ValidationException::class],
    'invalid CPF' => [['cpf' => '529.982.247-26'], InvalidArgumentException::class],
    'invalid Brazilian phone' => [['phone' => '559999999'], InvalidArgumentException::class],
]);

test('persists each non-draft status with complete fields and its status-specific requirements', function (RegistrationRequestStatus $status) {
    $attributes = ['status' => $status];

    if ($status === RegistrationRequestStatus::Approved) {
        $attributes['supervisor_affiliation_id'] = Affiliation::factory()->supervisor()->create()->id;
    }

    if (in_array($status, [RegistrationRequestStatus::Rejected, RegistrationRequestStatus::Cancelled], true)) {
        $attributes['decision_reason'] = 'Dados cadastrais conferidos.';
    }

    $request = SupervisorRegistrationRequest::factory()->create($attributes)->fresh();

    expect($request->status)->toBe($status)
        ->and($request->name)->not->toBeEmpty()
        ->and($request->email)->not->toBeEmpty();
})->with([
    'submitted' => RegistrationRequestStatus::Submitted,
    'under review' => RegistrationRequestStatus::UnderReview,
    'approved' => RegistrationRequestStatus::Approved,
    'rejected' => RegistrationRequestStatus::Rejected,
    'cancelled' => RegistrationRequestStatus::Cancelled,
]);

test('casts status, phone and review timestamp and exposes affiliation relationships', function () {
    $supervisor = Affiliation::factory()->supervisor()->create();

    $request = SupervisorRegistrationRequest::factory()->create([
        'status' => RegistrationRequestStatus::Approved,
        'cpf' => '529.982.247-25',
        'phone' => '(55) 99999-9999',
        'supervisor_affiliation_id' => $supervisor->id,
        'reviewed_at' => '2026-09-23 12:30:00',
    ])->fresh();

    expect($request->status)->toBe(RegistrationRequestStatus::Approved)
        ->and($request->cpf)->toBe('52998224725')
        ->and($request->phone)->toBe('55999999999')
        ->and($request->reviewed_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($request->supervisorAffiliation->is($supervisor))->toBeTrue()
        ->and($supervisor->supervisorRegistrationRequests()->whereKey($request->id)->exists())->toBeTrue()
        ->and($request->toArray())->not->toHaveKey('cpf');
});

test('requires a supervisor affiliation for approval and rejects affiliations of other types', function () {
    expect(fn () => SupervisorRegistrationRequest::factory()->create([
        'status' => RegistrationRequestStatus::Approved,
    ]))->toThrow(ValidationException::class);

    $otherAffiliation = Affiliation::factory()->server()->create();

    expect(fn () => SupervisorRegistrationRequest::factory()->create([
        'status' => RegistrationRequestStatus::Approved,
        'supervisor_affiliation_id' => $otherAffiliation->id,
    ]))->toThrow(ValidationException::class);

    $supervisor = Affiliation::factory()->supervisor()->create();
    $request = SupervisorRegistrationRequest::factory()->create([
        'status' => RegistrationRequestStatus::Approved,
        'supervisor_affiliation_id' => $supervisor->id,
    ]);

    expect($request->supervisorAffiliation->is($supervisor))->toBeTrue()
        ->and($supervisor->supervisorRegistrationRequests()->whereKey($request->id)->exists())->toBeTrue();
});

test('requires a reason for rejected and cancelled decisions', function (RegistrationRequestStatus $status) {
    expect(fn () => SupervisorRegistrationRequest::factory()->create([
        'status' => $status,
        'decision_reason' => '   ',
    ]))->toThrow(ValidationException::class);
})->with([
    'rejected' => RegistrationRequestStatus::Rejected,
    'cancelled' => RegistrationRequestStatus::Cancelled,
]);

test('accepts optional training and experience and preserves decision reasons', function () {
    $request = SupervisorRegistrationRequest::factory()->create([
        'status' => RegistrationRequestStatus::Rejected,
        'training' => 'Curso de segurança do trabalho.',
        'professional_experience' => 'Atuação como supervisora desde 2020.',
        'decision_reason' => '  Documento profissional pendente.  ',
    ])->fresh();

    expect($request->training)->toBe('Curso de segurança do trabalho.')
        ->and($request->professional_experience)->toBe('Atuação como supervisora desde 2020.')
        ->and($request->decision_reason)->toBe('Documento profissional pendente.');
});

test('restricts physical deletion of the resulting supervisor affiliation and registration request', function () {
    $supervisor = Affiliation::factory()->supervisor()->create();
    $request = SupervisorRegistrationRequest::factory()->create([
        'status' => RegistrationRequestStatus::Approved,
        'supervisor_affiliation_id' => $supervisor->id,
        'reviewed_at' => now(),
    ]);

    expect(fn () => DB::transaction(fn () => $supervisor->delete()))->toThrow(QueryException::class);
    $this->assertModelExists($supervisor);

    expect(fn () => $request->delete())->toThrow(ValidationException::class)
        ->and(Schema::hasTable('supervisor_registration_requests'))->toBeTrue();

    $this->assertModelExists($request);
});
