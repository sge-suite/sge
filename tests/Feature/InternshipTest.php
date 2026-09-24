<?php

use App\Enums\AffiliationType;
use App\Enums\InternshipStatus;
use App\Models\Affiliation;
use App\Models\Course;
use App\Models\Internship;
use App\Models\InternshipType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

test('creates the internships schema with historical snapshots and no formula JSONB', function () {
    expect(DB::getDriverName())->toBe('pgsql');

    $columns = collect(Schema::getColumns('internships'))->keyBy('name');

    expect($columns->keys()->all())->toBe([
        'id', 'student_affiliation_id', 'advisor_affiliation_id',
        'supervisor_affiliation_id', 'student_address_id', 'course_id',
        'internship_type_id', 'granting_party_id', 'workplace_address_id',
        'student_snapshot', 'internship_type_snapshot', 'granting_party_snapshot',
        'supervisor_snapshot', 'weekly_hours', 'activities', 'internship_sector',
        'planned_start_date', 'projected_end_date', 'released_at',
        'released_by_affiliation_id', 'is_remunerated', 'grant_value',
        'transportation_allowance', 'protocol_number', 'observations',
        'supervisor_grade', 'report_grade', 'presentation_grade',
        'report_graded_by_affiliation_id', 'presentation_graded_by_affiliation_id',
        'report_graded_at', 'presentation_graded_at', 'consolidated_grade',
        'status', 'created_at', 'updated_at',
    ]);

    foreach (['student_snapshot', 'internship_type_snapshot', 'granting_party_snapshot', 'supervisor_snapshot', 'weekly_hours'] as $column) {
        expect($columns->get($column))->toMatchArray(['type' => 'jsonb', 'nullable' => false]);
    }

    expect($columns->get('student_affiliation_id'))->toMatchArray(['type' => 'bigint', 'nullable' => false])
        ->and($columns->get('student_address_id'))->toMatchArray(['type' => 'bigint', 'nullable' => true])
        ->and($columns->get('planned_start_date'))->toMatchArray(['type' => 'date', 'nullable' => false])
        ->and($columns->get('projected_end_date'))->toMatchArray(['type' => 'date', 'nullable' => false])
        ->and($columns->get('grant_value'))->toMatchArray(['type' => 'numeric(10,2)', 'nullable' => true])
        ->and($columns->get('status'))->toMatchArray(['type' => 'character varying(255)', 'nullable' => false])
        ->and(Schema::hasColumn('internships', 'student_user_id'))->toBeFalse()
        ->and(Schema::hasColumn('internships', 'projected_end_date_calculation'))->toBeFalse();
});

test('restricts deletion of referenced records through all internship foreign keys', function () {
    $foreignKeys = collect(Schema::getForeignKeys('internships'));

    foreach ([
        'student_affiliation_id' => 'affiliations',
        'advisor_affiliation_id' => 'affiliations',
        'supervisor_affiliation_id' => 'affiliations',
        'student_address_id' => 'addresses',
        'course_id' => 'courses',
        'internship_type_id' => 'internship_types',
        'granting_party_id' => 'granting_parties',
        'workplace_address_id' => 'addresses',
        'released_by_affiliation_id' => 'affiliations',
        'report_graded_by_affiliation_id' => 'affiliations',
        'presentation_graded_by_affiliation_id' => 'affiliations',
    ] as $column => $table) {
        expect($foreignKeys->firstWhere('columns', [$column]))->toMatchArray([
            'foreign_table' => $table,
            'foreign_columns' => ['id'],
            'on_delete' => 'restrict',
        ]);
    }

    $internship = Internship::factory()->create();

    foreach ([
        ['affiliations', $internship->student_affiliation_id],
        ['courses', $internship->course_id],
        ['internship_types', $internship->internship_type_id],
        ['granting_parties', $internship->granting_party_id],
        ['addresses', $internship->workplace_address_id],
    ] as [$table, $id]) {
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))
            ->toThrow(QueryException::class);
    }
});

test('rolls back and reapplies the internships migration', function () {
    $path = glob(database_path('migrations/*_create_internships_table.php'))[0];
    $batch = DB::table('migrations')->where('migration', pathinfo($path, PATHINFO_FILENAME))->value('batch');

    $this->artisan('migrate:rollback', [
        '--path' => [$path], '--realpath' => true, '--batch' => $batch, '--no-interaction' => true,
    ])->assertSuccessful();

    expect(Schema::hasTable('internships'))->toBeFalse()
        ->and(Schema::hasTable('affiliations'))->toBeTrue();

    $this->artisan('migrate', [
        '--path' => [$path], '--realpath' => true, '--no-interaction' => true,
    ])->assertSuccessful();

    expect(Schema::hasTable('internships'))->toBeTrue();
});

test('casts status, snapshots, dates, remuneration and follows the affiliation relations', function () {
    $internship = Internship::factory()->remunerated()->released()->create()->fresh();

    expect($internship->status)->toBe(InternshipStatus::Released)
        ->and($internship->weekly_hours)->toBeArray()
        ->and($internship->student_snapshot)->toBeArray()
        ->and($internship->planned_start_date)->toBeInstanceOf(DateTimeInterface::class)
        ->and($internship->projected_end_date)->toBeInstanceOf(DateTimeInterface::class)
        ->and($internship->released_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($internship->is_remunerated)->toBeTrue()
        ->and($internship->grant_value)->toBe('600.00')
        ->and($internship->studentAffiliation->type)->toBe(AffiliationType::Student)
        ->and($internship->advisorAffiliation->type)->toBe(AffiliationType::Advisor)
        ->and($internship->supervisorAffiliation->type)->toBe(AffiliationType::Supervisor)
        ->and($internship->course->id)->toBe($internship->course_id)
        ->and($internship->internshipType->id)->toBe($internship->internship_type_id)
        ->and($internship->grantingParty->id)->toBe($internship->granting_party_id)
        ->and($internship->workplaceAddress->id)->toBe($internship->workplace_address_id);
});

test('keeps the student and granting party snapshots after source data changes', function () {
    $internship = Internship::factory()->create();
    $studentSnapshot = $internship->student_snapshot;
    $partySnapshot = $internship->granting_party_snapshot;

    $internship->studentAffiliation->user->update(['name' => 'Nome atualizado']);
    $internship->grantingParty->update(['name' => 'Concedente atualizada']);

    expect($internship->fresh()->student_snapshot)->toBe($studentSnapshot)
        ->and($internship->fresh()->granting_party_snapshot)->toBe($partySnapshot);
});

test('keeps historical course and workload rules when current records change', function () {
    $internship = Internship::factory()->create();
    $newCourse = Course::factory()->create(['campus_id' => $internship->course->campus_id]);
    $internship->studentAffiliation->update(['course_id' => $newCourse->id]);
    $internship->internshipType->update(['max_daily_hours' => 8, 'max_weekly_hours' => 40]);

    $internship->update(['status' => InternshipStatus::AwaitingSignatures]);

    expect($internship->fresh()->course_id)->not->toBe($newCourse->id)
        ->and($internship->fresh()->status)->toBe(InternshipStatus::AwaitingSignatures);

    $hours = $internship->weekly_hours;
    $hours['monday'] = 7;

    expect(fn () => $internship->update(['weekly_hours' => $hours]))
        ->toThrow(ValidationException::class);
});

test('requires the student affiliation to belong to the selected course', function () {
    $other = Affiliation::factory()->student()->create();

    expect(fn () => Internship::factory()->create(['student_affiliation_id' => $other->id]))
        ->toThrow(ValidationException::class);
});

test('requires the selected internship type to belong to the course', function () {
    $other = InternshipType::factory()->create();

    expect(fn () => Internship::factory()->create(['internship_type_id' => $other->id]))
        ->toThrow(ValidationException::class);
});

test('requires every day and validates the initial weekly hours against the type limits', function (array $hours) {
    expect(fn () => Internship::factory()->create(['weekly_hours' => $hours]))
        ->toThrow(ValidationException::class);
})->with([
    [['monday' => 5]],
    [['sunday' => 0, 'monday' => 7, 'tuesday' => 4, 'wednesday' => 4, 'thursday' => 4, 'friday' => 4, 'saturday' => 0]],
    [['sunday' => 0, 'monday' => 6, 'tuesday' => 6, 'wednesday' => 6, 'thursday' => 6, 'friday' => 6, 'saturday' => 6]],
    [['sunday' => 0, 'monday' => 0, 'tuesday' => 0, 'wednesday' => 0, 'thursday' => 0, 'friday' => 0, 'saturday' => 0]],
]);

test('requires the historical values and calculated date before creating an internship', function (string $field) {
    $internship = Internship::factory()->create();

    expect(fn () => $internship->update([$field => null]))
        ->toThrow(ValidationException::class);
})->with([
    'student_affiliation_id', 'advisor_affiliation_id', 'supervisor_affiliation_id',
    'course_id', 'internship_type_id', 'granting_party_id', 'workplace_address_id',
    'student_snapshot', 'internship_type_snapshot', 'granting_party_snapshot',
    'supervisor_snapshot', 'weekly_hours', 'activities', 'planned_start_date',
    'projected_end_date',
]);

test('requires a positive grant when remunerated', function () {
    expect(fn () => Internship::factory()->create(['is_remunerated' => true, 'grant_value' => null]))
        ->toThrow(ValidationException::class);

    expect(Internship::factory()->remunerated()->create()->grant_value)->toBe('600.00');
});

test('starts in pending formalization and logs later status changes', function () {
    $internship = Internship::factory()->create();
    $internship->update(['status' => InternshipStatus::AwaitingSignatures]);

    expect($internship->fresh()->status)->toBe(InternshipStatus::AwaitingSignatures)
        ->and(Activity::forSubject($internship)->count())->toBe(2);
});

test('rejects an invalid supervisor affiliation', function () {
    $student = Affiliation::factory()->student()->create();

    expect(fn () => Internship::factory()->create(['supervisor_affiliation_id' => $student->id]))
        ->toThrow(ValidationException::class);
});

test('keeps nullable student address and optional values', function () {
    $internship = Internship::factory()->create([
        'student_address_id' => null,
        'internship_sector' => null,
        'protocol_number' => null,
    ])->fresh();

    expect($internship->studentAddress)->toBeNull()
        ->and($internship->internship_sector)->toBeNull()
        ->and($internship->protocol_number)->toBeNull();
});
