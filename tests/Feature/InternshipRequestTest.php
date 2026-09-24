<?php

use App\Enums\AffiliationType;
use App\Enums\InternshipRequestStatus;
use App\Enums\LegalCapacityDeclaration;
use App\Models\Affiliation;
use App\Models\Course;
use App\Models\GrantingPartyRegistrationRequest;
use App\Models\Internship;
use App\Models\InternshipRequest;
use App\Models\InternshipType;
use App\Models\SupervisorRegistrationRequest;
use App\Models\UserPersonalData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

test('creates the PostgreSQL internship request schema without a redundant consent flag or version', function () {
    expect(DB::getDriverName())->toBe('pgsql');

    $columns = collect(Schema::getColumns('internship_requests'))->keyBy('name');
    expect($columns->keys()->all())->toBe([
        'id', 'affiliation_id', 'course_id', 'internship_type_id',
        'advisor_affiliation_id', 'granting_party_id',
        'granting_party_registration_request_id', 'supervisor_affiliation_id',
        'supervisor_registration_request_id', 'student_year_semester',
        'legal_capacity_declaration', 'legal_guardian_name', 'legal_guardian_cpf',
        'legal_guardian_kinship', 'legal_guardian_email', 'activities',
        'internship_sector', 'weekly_hours', 'planned_start_date',
        'projected_end_date', 'is_remunerated', 'grant_value',
        'transportation_allowance', 'observations', 'status', 'internship_id',
        'terms_accepted_at', 'created_at', 'updated_at',
    ])->and($columns->get('weekly_hours'))->toMatchArray(['type' => 'jsonb', 'nullable' => true])
        ->and($columns->get('legal_guardian_cpf'))->toMatchArray(['type' => 'character varying(255)', 'nullable' => true])
        ->and($columns->get('terms_accepted_at'))->toMatchArray(['nullable' => true])
        ->and($columns->has('terms_accepted'))->toBeFalse()
        ->and($columns->has('terms_version'))->toBeFalse()
        ->and($columns->has('terms_content_hash'))->toBeFalse();

    $foreignKeys = collect(Schema::getForeignKeys('internship_requests'));
    foreach ([
        'affiliation_id' => 'affiliations', 'course_id' => 'courses',
        'internship_type_id' => 'internship_types',
        'advisor_affiliation_id' => 'affiliations',
        'granting_party_id' => 'granting_parties',
        'granting_party_registration_request_id' => 'granting_party_registration_requests',
        'supervisor_affiliation_id' => 'affiliations',
        'supervisor_registration_request_id' => 'supervisor_registration_requests',
        'internship_id' => 'internships',
    ] as $column => $table) {
        expect($foreignKeys->firstWhere('columns', [$column]))->toMatchArray([
            'foreign_table' => $table, 'foreign_columns' => ['id'], 'on_delete' => 'restrict',
        ]);
    }

    expect(collect(Schema::getIndexes('internship_requests'))->firstWhere('columns', ['internship_id']))
        ->toMatchArray(['unique' => true]);
});

test('persists a partial draft for the student affiliation and records changes in the activity log', function () {
    $request = InternshipRequest::factory()->create()->fresh();
    $request->update(['activities' => 'Observar atividades.']);

    expect($request->status)->toBe(InternshipRequestStatus::Draft)
        ->and($request->course_id)->toBeNull()
        ->and($request->terms_accepted_at)->toBeNull()
        ->and($request->affiliation->type)->toBe(AffiliationType::Student)
        ->and($request->affiliation->internshipRequests)->toHaveCount(1)
        ->and(Activity::forSubject($request)->count())->toBe(2);

    expect(fn () => $request->delete())->toThrow(ValidationException::class);
});

test('requires the explicit terms acceptance timestamp and complete fields on submission', function () {
    expect(fn () => InternshipRequest::factory()->submitted()->create(['terms_accepted_at' => null]))
        ->toThrow(ValidationException::class);

    $request = InternshipRequest::factory()->submitted()->create()->fresh();

    expect($request->terms_accepted_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($request->weekly_hours)->toBeArray()
        ->and($request->is_remunerated)->toBeFalse()
        ->and($request->legal_capacity_declaration)->toBe(LegalCapacityDeclaration::Minor)
        ->and($request->legal_guardian_cpf)->toBe('52998224725')
        ->and($request->grant_value)->toBeNull();
});

test('requires the course to belong to the student and the internship type to belong to the course', function () {
    $student = Affiliation::factory()->student()->create();
    $otherCourse = Course::factory()->create();

    expect(fn () => InternshipRequest::factory()->create([
        'affiliation_id' => $student->id,
        'course_id' => $otherCourse->id,
    ]))->toThrow(ValidationException::class);

    $otherType = InternshipType::factory()->create();
    expect(fn () => InternshipRequest::factory()->create([
        'affiliation_id' => $student->id,
        'course_id' => $student->course_id,
        'internship_type_id' => $otherType->id,
    ]))->toThrow(ValidationException::class);

    expect(fn () => InternshipRequest::factory()->create([
        'affiliation_id' => Affiliation::factory()->server()->create()->id,
    ]))->toThrow(ValidationException::class);
});

test('accepts exactly one reference for each granting party and supervisor path', function () {
    $request = InternshipRequest::factory()->submitted()->create();
    expect($request->grantingParty)->not->toBeNull()
        ->and($request->supervisorAffiliation)->not->toBeNull();

    expect(fn () => InternshipRequest::factory()->submitted()->create([
        'granting_party_id' => null,
        'granting_party_registration_request_id' => null,
    ]))->toThrow(ValidationException::class);

    expect(fn () => InternshipRequest::factory()->submitted()->create([
        'supervisor_affiliation_id' => null,
        'supervisor_registration_request_id' => null,
    ]))->toThrow(ValidationException::class);

    $partyRequest = GrantingPartyRegistrationRequest::factory()->create();
    $supervisorRequest = SupervisorRegistrationRequest::factory()->create();
    $existingPartyId = $request->granting_party_id;
    $request->update([
        'granting_party_id' => null,
        'granting_party_registration_request_id' => $partyRequest->id,
        'supervisor_affiliation_id' => null,
        'supervisor_registration_request_id' => $supervisorRequest->id,
    ]);

    expect($request->fresh()->grantingPartyRegistrationRequest->id)->toBe($partyRequest->id)
        ->and($request->fresh()->supervisorRegistrationRequest->id)->toBe($supervisorRequest->id);

    expect(fn () => $request->update(['granting_party_id' => $existingPartyId]))
        ->toThrow(ValidationException::class);
});

test('requires guardian fields only for a minor and validates the CPF with the existing cast', function () {
    expect(fn () => InternshipRequest::factory()->submitted()->create(['legal_guardian_name' => null]))
        ->toThrow(ValidationException::class);

    expect(fn () => InternshipRequest::factory()->submitted()->create(['legal_guardian_cpf' => '11111111111']))
        ->toThrow(InvalidArgumentException::class);

    $request = InternshipRequest::factory()->submitted()->create();
    expect($request->getRawOriginal('legal_guardian_cpf'))->toBe('52998224725');
});

test('rejects adult declaration without 18 completed years at the recorded acceptance date', function () {
    $request = InternshipRequest::factory()->submitted()->make([
        'legal_capacity_declaration' => LegalCapacityDeclaration::Adult,
        'legal_guardian_name' => null,
        'legal_guardian_cpf' => null,
        'legal_guardian_kinship' => null,
        'legal_guardian_email' => null,
    ]);
    UserPersonalData::factory()->create([
        'user_id' => $request->affiliation->user_id,
        'birth_date' => now()->subYears(17)->toDateString(),
    ]);

    expect(fn () => $request->save())->toThrow(ValidationException::class);

    $request->affiliation->user->personalData->update(['birth_date' => now()->subYears(19)->toDateString()]);
    $request->save();
    expect($request->fresh()->legal_capacity_declaration)->toBe(LegalCapacityDeclaration::Adult);
});

test('validates every weekday and the daily and weekly limits of the selected type', function (array $hours) {
    expect(fn () => InternshipRequest::factory()->submitted()->create(['weekly_hours' => $hours]))
        ->toThrow(ValidationException::class);
})->with([
    [['monday' => 4]],
    [['sunday' => 0, 'monday' => 7, 'tuesday' => 4, 'wednesday' => 4, 'thursday' => 4, 'friday' => 4, 'saturday' => 0]],
    [['sunday' => 0, 'monday' => 6, 'tuesday' => 6, 'wednesday' => 6, 'thursday' => 6, 'friday' => 6, 'saturday' => 6]],
    [['sunday' => 0, 'monday' => 0, 'tuesday' => 0, 'wednesday' => 0, 'thursday' => 0, 'friday' => 0, 'saturday' => 0]],
]);

test('requires positive grant for paid internships and no monetary values for unpaid ones', function () {
    expect(fn () => InternshipRequest::factory()->submitted()->create([
        'is_remunerated' => true,
        'grant_value' => null,
    ]))->toThrow(ValidationException::class);

    expect(fn () => InternshipRequest::factory()->submitted()->create([
        'is_remunerated' => false,
        'grant_value' => 10,
    ]))->toThrow(ValidationException::class);

    $request = InternshipRequest::factory()->submitted()->create([
        'is_remunerated' => true,
        'grant_value' => 600,
        'transportation_allowance' => 0,
    ])->fresh();

    expect($request->grant_value)->toBe('600.00')
        ->and($request->transportation_allowance)->toBe('0.00');
});

test('requires the advisor, resolved references and unique stage association on acceptance', function () {
    $request = InternshipRequest::factory()->submitted()->create();
    expect(fn () => $request->update(['status' => InternshipRequestStatus::Accepted]))
        ->toThrow(ValidationException::class);

    $advisor = Affiliation::factory()->create(['type' => AffiliationType::Advisor]);
    $internship = Internship::factory()->create([
        'student_affiliation_id' => $request->affiliation_id,
        'course_id' => $request->course_id,
        'internship_type_id' => $request->internship_type_id,
        'advisor_affiliation_id' => $advisor->id,
        'granting_party_id' => $request->granting_party_id,
        'supervisor_affiliation_id' => $request->supervisor_affiliation_id,
    ]);

    $request->update([
        'status' => InternshipRequestStatus::Accepted,
        'advisor_affiliation_id' => $advisor->id,
        'internship_id' => $internship->id,
    ]);

    expect($request->fresh()->internship->id)->toBe($internship->id)
        ->and($internship->internshipRequest->id)->toBe($request->id);

    expect(fn () => DB::transaction(fn () => DB::table('internship_requests')->insert([
        'affiliation_id' => $request->affiliation_id,
        'status' => 'accepted',
        'internship_id' => $internship->id,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});

test('preserves an incomplete withdrawn draft without physical deletion', function () {
    $request = InternshipRequest::factory()->create();
    $request->update(['status' => InternshipRequestStatus::Withdrawn]);

    expect($request->fresh()->status)->toBe(InternshipRequestStatus::Withdrawn)
        ->and($request->fresh()->terms_accepted_at)->toBeNull();
});

test('rolls back and reapplies the internship request migration', function () {
    $path = glob(database_path('migrations/*_create_internship_requests_table.php'))[0];
    $batch = DB::table('migrations')->where('migration', pathinfo($path, PATHINFO_FILENAME))->value('batch');

    $this->artisan('migrate:rollback', [
        '--path' => [$path], '--realpath' => true, '--batch' => $batch, '--no-interaction' => true,
    ])->assertSuccessful();

    expect(Schema::hasTable('internship_requests'))->toBeFalse();

    $this->artisan('migrate', [
        '--path' => [$path], '--realpath' => true, '--no-interaction' => true,
    ])->assertSuccessful();

    expect(Schema::hasTable('internship_requests'))->toBeTrue();
});
