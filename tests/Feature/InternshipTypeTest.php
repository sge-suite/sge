<?php

use App\Models\Campus;
use App\Models\Course;
use App\Models\InternshipType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

test('creates internship types with scalar configuration columns and a required course foreign key', function () {
    expect(DB::getDriverName())->toBe('pgsql');

    $columns = collect(Schema::getColumns('internship_types'))->keyBy('name');
    $foreignKeys = collect(Schema::getForeignKeys('internship_types'));
    $indexes = collect(Schema::getIndexes('internship_types'));
    $checkConstraints = DB::select("SELECT conname FROM pg_constraint WHERE conrelid = 'internship_types'::regclass AND contype = 'c'");

    expect($columns->keys()->all())->toBe([
        'id', 'course_id', 'name', 'required_hours', 'supervisor_evaluation_weight', 'report_weight',
        'presentation_weight', 'very_good_value', 'good_value', 'satisfactory_value', 'unsatisfactory_value',
        'max_daily_hours', 'max_weekly_hours', 'safety_margin_days',
        'deactivated_at', 'created_at', 'updated_at',
    ])
        ->and($columns->get('id'))->toMatchArray(['type' => 'bigint', 'nullable' => false])
        ->and($columns->get('course_id'))->toMatchArray(['type' => 'bigint', 'nullable' => false])
        ->and($columns->get('required_hours'))->toMatchArray(['type' => 'integer', 'nullable' => false])
        ->and($columns->get('supervisor_evaluation_weight'))->toMatchArray(['type' => 'integer', 'nullable' => false])
        ->and($columns->get('very_good_value'))->toMatchArray(['type' => 'numeric(5,1)', 'nullable' => false])
        ->and($columns->get('unsatisfactory_value'))->toMatchArray(['type' => 'numeric(5,1)', 'nullable' => false])
        ->and($columns->get('deactivated_at'))->toMatchArray(['nullable' => true])
        ->and($columns->has('excellent_value'))->toBeFalse()
        ->and($foreignKeys)->toHaveCount(1)
        ->and($foreignKeys->firstWhere('columns', ['course_id']))->toMatchArray(['foreign_table' => 'courses', 'on_delete' => 'restrict'])
        ->and($checkConstraints)->toBeEmpty()
        ->and($indexes)->toHaveCount(1)
        ->and($indexes->firstWhere('primary', true))->toMatchArray(['columns' => ['id'], 'unique' => true]);
});

test('persists scalar rules with decimal casts and relates active types to their course', function () {
    $course = Course::factory()->create();
    $internshipType = InternshipType::factory()->for($course)->create();
    $storedType = $internshipType->fresh();

    expect($storedType->required_hours)->toBeInt()
        ->and($storedType->supervisor_evaluation_weight)->toBe(4)
        ->and($storedType->unsatisfactory_value)->toBe('0.0')
        ->and($storedType->max_daily_hours)->toBe(6)
        ->and($storedType->max_weekly_hours)->toBe(30)
        ->and($storedType->safety_margin_days)->toBe(7)
        ->and($storedType->course->is($course))->toBeTrue()
        ->and($course->internshipTypes()->whereKey($internshipType->id)->exists())->toBeTrue()
        ->and(InternshipType::active()->whereKey($internshipType->id)->exists())->toBeTrue();

    $internshipType->update(['deactivated_at' => now()]);

    expect(InternshipType::active()->whereKey($internshipType->id)->exists())->toBeFalse();
});

test('isolates types by course and campus when querying for a selected course', function () {
    $campus = Campus::factory()->create();
    $firstCourse = Course::factory()->for($campus)->create();
    $secondCourse = Course::factory()->for($campus)->create();
    $otherCampusCourse = Course::factory()->create();
    $firstType = InternshipType::factory()->for($firstCourse)->create();
    InternshipType::factory()->for($secondCourse)->create();
    InternshipType::factory()->for($otherCampusCourse)->create();

    expect(InternshipType::active()->whereBelongsTo($firstCourse)->pluck('id')->all())->toBe([$firstType->id])
        ->and($firstCourse->internshipTypes()->pluck('id')->all())->toBe([$firstType->id]);
});

test('accepts integer weights, decimal concept values and workload limits above their minimums', function () {
    $internshipType = InternshipType::factory()->create();

    $internshipType->update([
        'supervisor_evaluation_weight' => 4,
        'report_weight' => 3,
        'presentation_weight' => 3,
        'very_good_value' => 3.5,
        'good_value' => 2,
        'satisfactory_value' => 1.5,
        'unsatisfactory_value' => 1,
        'max_daily_hours' => 8,
        'max_weekly_hours' => 40,
        'safety_margin_days' => 0,
    ]);

    expect($internshipType->fresh()->supervisor_evaluation_weight)->toBe(4)
        ->and($internshipType->fresh()->very_good_value)->toBe('3.5')
        ->and($internshipType->fresh()->satisfactory_value)->toBe('1.5')
        ->and($internshipType->fresh()->unsatisfactory_value)->toBe('1.0')
        ->and($internshipType->fresh()->max_daily_hours)->toBe(8)
        ->and($internshipType->fresh()->max_weekly_hours)->toBe(40)
        ->and($internshipType->fresh()->safety_margin_days)->toBe(0);
});

test('rejects invalid internship type values through Laravel validation', function (string $attribute, mixed $value) {
    $internshipType = InternshipType::factory()->create();

    expect(fn () => $internshipType->update([$attribute => $value]))->toThrow(ValidationException::class);
})->with([
    'required hours must be positive' => ['required_hours', 0],
    'required hours must be an integer' => ['required_hours', 300.5],
    'missing evaluation weight' => ['report_weight', null],
    'weights must sum to ten' => ['report_weight', 4],
    'weights must be numbers' => ['report_weight', 'three'],
    'weights must be integers' => ['report_weight', 3.5],
    'supervisor weight must be positive' => ['supervisor_evaluation_weight', 0],
    'report weight must be positive' => ['report_weight', 0],
    'presentation weight must be positive' => ['presentation_weight', 0],
    'concept values allow only one decimal place' => ['good_value', 2.55],
    'concept values cannot exceed the supervisor weight' => ['very_good_value', 5],
    'concept values must be strictly decreasing' => ['good_value', 3.5],
    'concept values cannot repeat' => ['very_good_value', 4],
    'unsatisfactory cannot equal satisfactory' => ['unsatisfactory_value', 1],
    'unsatisfactory value cannot be negative' => ['unsatisfactory_value', -0.1],
    'minimum daily limit is six' => ['max_daily_hours', 5],
    'minimum weekly limit is thirty' => ['max_weekly_hours', 29],
    'workload limits must be integers' => ['max_daily_hours', 6.5],
    'safety margin cannot be negative' => ['safety_margin_days', -1],
]);

test('reports internship type validation errors in Portuguese', function () {
    $internshipType = InternshipType::factory()->create();
    $validationException = null;

    try {
        $internshipType->update(['report_weight' => 3.5]);
    } catch (ValidationException $exception) {
        $validationException = $exception;
    }

    expect($validationException)->toBeInstanceOf(ValidationException::class)
        ->and($validationException->errors()['report_weight'][0])->toBe('O campo peso do relatório deve ser um número inteiro.');

    $internshipType->refresh();
    $validationException = null;

    try {
        $internshipType->update(['very_good_value' => 5]);
    } catch (ValidationException $exception) {
        $validationException = $exception;
    }

    expect($validationException)->toBeInstanceOf(ValidationException::class)
        ->and($validationException->errors()['very_good_value'][0])->toBe('O valor do conceito Muito bom não pode ultrapassar o peso da avaliação do supervisor.');
});

test('requires an active course when creating or reactivating a type', function () {
    $course = Course::factory()->create();
    $internshipType = InternshipType::factory()->for($course)->create();
    $course->update(['deactivated_at' => now()]);

    expect(fn () => InternshipType::factory()->for($course)->create())->toThrow(ValidationException::class);

    $otherCourse = Course::factory()->create();
    $otherCourse->update(['deactivated_at' => now()]);
    expect(fn () => $internshipType->update(['course_id' => $otherCourse->id]))->toThrow(ValidationException::class);

    $internshipType->refresh();
    $internshipType->update(['name' => 'Tipo histórico']);
    expect($internshipType->fresh()->course_id)->toBe($course->id);

    $internshipType->update(['deactivated_at' => now()]);
    expect(fn () => $internshipType->update(['deactivated_at' => null]))->toThrow(ValidationException::class);
});

test('restricts physical deletion of a course referenced by an internship type', function () {
    $internshipType = InternshipType::factory()->create();
    $course = $internshipType->course;

    expect(fn () => DB::transaction(fn () => $course->delete()))->toThrow(QueryException::class);
    $this->assertModelExists($course);
});
