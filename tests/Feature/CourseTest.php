<?php

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

test('creates courses and the student course foreign key in PostgreSQL', function () {
    expect(DB::getDriverName())->toBe('pgsql');

    $columns = collect(Schema::getColumns('courses'))->keyBy('name');
    $foreignKeys = collect(Schema::getForeignKeys('courses'));
    $indexes = collect(Schema::getIndexes('courses'));

    expect($columns->keys()->all())->toBe([
        'id', 'campus_id', 'name', 'primary_coordinator_affiliation_id',
        'secondary_coordinator_affiliation_id', 'deactivated_at', 'created_at', 'updated_at',
    ])
        ->and($columns->get('id'))->toMatchArray(['type' => 'bigint', 'nullable' => false])
        ->and($columns->get('campus_id'))->toMatchArray(['type' => 'bigint', 'nullable' => false])
        ->and($columns->get('primary_coordinator_affiliation_id'))->toMatchArray(['type' => 'bigint', 'nullable' => true])
        ->and($columns->get('secondary_coordinator_affiliation_id'))->toMatchArray(['type' => 'bigint', 'nullable' => true])
        ->and($foreignKeys)->toHaveCount(3)
        ->and($foreignKeys->firstWhere('columns', ['campus_id']))->toMatchArray(['foreign_table' => 'campuses', 'on_delete' => 'restrict'])
        ->and($foreignKeys->firstWhere('columns', ['primary_coordinator_affiliation_id']))->toMatchArray(['foreign_table' => 'affiliations', 'on_delete' => 'restrict'])
        ->and($foreignKeys->firstWhere('columns', ['secondary_coordinator_affiliation_id']))->toMatchArray(['foreign_table' => 'affiliations', 'on_delete' => 'restrict'])
        ->and($indexes->firstWhere('primary', true))->toMatchArray(['columns' => ['id'], 'unique' => true]);

    expect(collect(Schema::getColumns('affiliations'))->firstWhere('name', 'course_id'))
        ->toMatchArray(['type' => 'bigint', 'nullable' => true]);
});

test('creates a course without coordinators on an active campus', function () {
    $campus = Campus::factory()->create();
    $course = Course::factory()->for($campus)->create();

    expect($course->campus->is($campus))->toBeTrue()
        ->and($course->primaryCoordinator)->toBeNull()
        ->and($course->secondaryCoordinator)->toBeNull()
        ->and($campus->courses()->whereKey($course->id)->exists())->toBeTrue()
        ->and(Course::active()->whereKey($course->id)->exists())->toBeTrue();

    $course->update(['deactivated_at' => now()]);
    expect(Course::active()->whereKey($course->id)->exists())->toBeFalse();
});

test('accepts two distinct active coordinators from the same campus', function () {
    $campus = Campus::factory()->create();
    $primary = Affiliation::factory()->for($campus)->create(['type' => AffiliationType::Coordinator]);
    $secondary = Affiliation::factory()->for($campus)->create(['type' => AffiliationType::Coordinator]);
    $course = Course::factory()->for($campus)->create([
        'primary_coordinator_affiliation_id' => $primary->id,
        'secondary_coordinator_affiliation_id' => $secondary->id,
    ]);

    expect($course->primaryCoordinator->is($primary))->toBeTrue()
        ->and($course->secondaryCoordinator->is($secondary))->toBeTrue()
        ->and($primary->primaryCoordinatedCourses()->whereKey($course->id)->exists())->toBeTrue()
        ->and($secondary->secondaryCoordinatedCourses()->whereKey($course->id)->exists())->toBeTrue();
});

test('rejects invalid coordinator type, campus, inactive affiliation, and duplicate slots', function () {
    $campus = Campus::factory()->create();
    $otherCampus = Campus::factory()->create();
    $wrongType = Affiliation::factory()->for($campus)->create(['type' => AffiliationType::Advisor]);
    $otherCampusCoordinator = Affiliation::factory()->for($otherCampus)->create(['type' => AffiliationType::Coordinator]);
    $inactiveCoordinator = Affiliation::factory()->deactivated()->for($campus)->create(['type' => AffiliationType::Coordinator]);
    $validCoordinator = Affiliation::factory()->for($campus)->create(['type' => AffiliationType::Coordinator]);

    foreach ([$wrongType, $otherCampusCoordinator, $inactiveCoordinator] as $invalidCoordinator) {
        expect(fn () => Course::factory()->for($campus)->create(['primary_coordinator_affiliation_id' => $invalidCoordinator->id]))
            ->toThrow(ValidationException::class);
    }

    expect(fn () => Course::factory()->for($campus)->create([
        'primary_coordinator_affiliation_id' => $validCoordinator->id,
        'secondary_coordinator_affiliation_id' => $validCoordinator->id,
    ]))->toThrow(ValidationException::class);
});

test('rejects a course on an inactive campus but preserves existing references', function () {
    $campus = Campus::factory()->create();
    $course = Course::factory()->for($campus)->create();
    $campus->update(['deactivated_at' => now()]);

    expect(fn () => Course::factory()->for($campus)->create())->toThrow(ValidationException::class);
    expect(fn () => $course->update(['name' => 'Novo nome']))->not->toThrow(ValidationException::class);

    $course->update(['deactivated_at' => now()]);
    expect(fn () => $course->update(['deactivated_at' => null]))->toThrow(ValidationException::class);
});

test('requires a course only for students and keeps it in the same campus', function () {
    $campus = Campus::factory()->create();
    $course = Course::factory()->for($campus)->create();
    $otherCourse = Course::factory()->create();
    $user = User::factory()->create();
    $student = Affiliation::factory()->student()->for($user)->for($campus)->create(['course_id' => $course->id]);

    expect($student->course->is($course))->toBeTrue()
        ->and($course->studentAffiliations()->whereKey($student->id)->exists())->toBeTrue();

    expect(fn () => Affiliation::factory()->student()->for($campus)->create(['course_id' => null]))
        ->toThrow(ValidationException::class)
        ->and(fn () => Affiliation::factory()->student()->for($campus)->create(['course_id' => $otherCourse->id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => Affiliation::factory()->server()->for($campus)->create(['course_id' => $course->id]))
        ->toThrow(ValidationException::class);
});

test('uses separate student affiliations for two courses of the same account', function () {
    $campus = Campus::factory()->create();
    $firstCourse = Course::factory()->for($campus)->create();
    $secondCourse = Course::factory()->for($campus)->create();
    $user = User::factory()->create();
    $first = Affiliation::factory()->student()->for($user)->for($campus)->create(['course_id' => $firstCourse->id]);
    $second = Affiliation::factory()->student()->for($user)->for($campus)->create(['course_id' => $secondCourse->id]);

    expect($first->user_id)->toBe($second->user_id)
        ->and($first->id)->not->toBe($second->id)
        ->and($first->course_id)->toBe($firstCourse->id)
        ->and($second->course_id)->toBe($secondCourse->id);
});

test('rejects a newly assigned inactive course while preserving existing student history', function () {
    $campus = Campus::factory()->create();
    $course = Course::factory()->for($campus)->create();
    $student = Affiliation::factory()->student()->for($campus)->create(['course_id' => $course->id]);
    $course->update(['deactivated_at' => now()]);

    expect(fn () => Affiliation::factory()->student()->for($campus)->create(['course_id' => $course->id]))
        ->toThrow(ValidationException::class);

    $student->update(['email' => 'novo@example.test']);
    expect($student->fresh()->course_id)->toBe($course->id);

    $student->update(['deactivated_at' => now()]);
    expect(fn () => $student->update(['deactivated_at' => null]))->toThrow(ValidationException::class);
});

test('restricts physical deletion of a referenced course', function () {
    $student = Affiliation::factory()->student()->create();
    $course = $student->course;

    expect(fn () => DB::transaction(fn () => $course->delete()))->toThrow(QueryException::class);
    $this->assertModelExists($course);
});

test('rolls back and reapplies course dependencies in foreign key order', function () {
    $coursePath = glob(database_path('migrations/*_create_courses_table.php'))[0];
    $affiliationCoursePath = glob(database_path('migrations/*_add_course_id_to_affiliations_table.php'))[0];
    $internshipTypePath = glob(database_path('migrations/*_create_internship_types_table.php'))[0];
    $internshipPath = glob(database_path('migrations/*_create_internships_table.php'))[0];
    $evaluationPath = glob(database_path('migrations/*_create_supervisor_evaluations_table.php'))[0];
    $requestPath = glob(database_path('migrations/*_create_internship_requests_table.php'))[0];

    $laterPaths = array_map(
        fn (string $name): string => glob(database_path("migrations/*_create_{$name}_table.php"))[0],
        ['internship_work_schedules', 'internship_calendar_overrides', 'internship_cancellation_requests',
            'internship_request_corrections', 'emancipation_evidences', 'internship_pauses', 'generated_documents'],
    );

    foreach ([...$laterPaths, $requestPath, $evaluationPath, $internshipPath, $internshipTypePath, $affiliationCoursePath, $coursePath] as $path) {
        $batch = DB::table('migrations')->where('migration', pathinfo($path, PATHINFO_FILENAME))->value('batch');
        $this->artisan('migrate:rollback', [
            '--path' => [$path], '--realpath' => true, '--batch' => $batch, '--no-interaction' => true,
        ])->assertSuccessful();
    }

    expect(Schema::hasColumn('affiliations', 'course_id'))->toBeFalse()
        ->and(Schema::hasTable('courses'))->toBeFalse()
        ->and(Schema::hasTable('internship_types'))->toBeFalse()
        ->and(Schema::hasTable('affiliations'))->toBeTrue();

    foreach ([$coursePath, $affiliationCoursePath, $internshipTypePath, $internshipPath, $evaluationPath, $requestPath, ...array_reverse($laterPaths)] as $path) {
        $this->artisan('migrate', ['--path' => [$path], '--realpath' => true, '--no-interaction' => true])->assertSuccessful();
    }

    expect(Schema::hasTable('courses'))->toBeTrue()
        ->and(Schema::hasColumn('affiliations', 'course_id'))->toBeTrue()
        ->and(Schema::hasTable('internship_types'))->toBeTrue();
});
