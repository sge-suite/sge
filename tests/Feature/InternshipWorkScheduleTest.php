<?php

use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Models\GeneratedDocument;
use App\Models\InternshipWorkSchedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

test('creates the PostgreSQL work schedule schema with JSONB and restricted document and stage FKs', function () {
    expect(DB::getDriverName())->toBe('pgsql');
    $columns = collect(Schema::getColumns('internship_work_schedules'))->keyBy('name');
    expect($columns->keys()->all())->toBe([
        'id', 'internship_id', 'starts_on', 'ends_on', 'weekly_hours',
        'generated_document_id', 'created_at', 'updated_at',
    ])->and($columns->get('weekly_hours'))->toMatchArray(['type' => 'jsonb', 'nullable' => false])
        ->and($columns->get('ends_on'))->toMatchArray(['type' => 'date', 'nullable' => true])
        ->and($columns->has('created_by_affiliation_id'))->toBeFalse();
    $keys = collect(Schema::getForeignKeys('internship_work_schedules'));
    expect($keys->firstWhere('columns', ['internship_id']))->toMatchArray(['foreign_table' => 'internships', 'on_delete' => 'restrict'])
        ->and($keys->firstWhere('columns', ['generated_document_id']))->toMatchArray(['foreign_table' => 'generated_documents', 'on_delete' => 'restrict']);
});

test('stores a later weekly schedule only with a signed addendum for its internship', function () {
    $schedule = InternshipWorkSchedule::factory()->create()->fresh();
    expect($schedule->weekly_hours)->toBeArray()
        ->and($schedule->starts_on)->toBeInstanceOf(DateTimeInterface::class)
        ->and($schedule->generatedDocument->type)->toBe(GeneratedDocumentType::Addendum)
        ->and($schedule->generatedDocument->status)->toBe(GeneratedDocumentStatus::Signed)
        ->and($schedule->internship->workSchedules)->toHaveCount(1)
        ->and($schedule->generatedDocument->workSchedules)->toHaveCount(1)
        ->and(Activity::forSubject($schedule)->count())->toBe(1);
    expect(fn () => $schedule->delete())->toThrow(ValidationException::class);
});

test('rejects unsigned or unrelated documents, malformed hours and a start before the initial schedule', function () {
    $schedule = InternshipWorkSchedule::factory()->create();
    $unsigned = GeneratedDocument::factory()->create([
        'internship_id' => $schedule->internship_id,
        'type' => GeneratedDocumentType::Addendum,
    ]);
    expect(fn () => InternshipWorkSchedule::factory()->create([
        'internship_id' => $schedule->internship_id,
        'generated_document_id' => $unsigned->id,
        'starts_on' => $schedule->starts_on->copy()->addMonth(),
    ]))->toThrow(ValidationException::class);

    $otherDocument = GeneratedDocument::factory()->create(['type' => GeneratedDocumentType::Addendum, 'status' => GeneratedDocumentStatus::Signed]);
    expect(fn () => InternshipWorkSchedule::factory()->create([
        'internship_id' => $schedule->internship_id,
        'generated_document_id' => $otherDocument->id,
        'starts_on' => $schedule->starts_on->copy()->addMonth(),
    ]))->toThrow(ValidationException::class);
    expect(fn () => InternshipWorkSchedule::factory()->create(['weekly_hours' => ['monday' => 4]]))
        ->toThrow(ValidationException::class);
    expect(fn () => InternshipWorkSchedule::factory()->create([
        'starts_on' => $schedule->internship->planned_start_date->copy()->subDay(),
    ]))->toThrow(ValidationException::class);
});

test('enforces daily and weekly limits from the internship type snapshot', function () {
    $dailyExcess = ['sunday' => 0, 'monday' => 7, 'tuesday' => 0, 'wednesday' => 0, 'thursday' => 0, 'friday' => 0, 'saturday' => 0];
    expect(fn () => InternshipWorkSchedule::factory()->create(['weekly_hours' => $dailyExcess]))
        ->toThrow(ValidationException::class);

    $weeklyExcess = ['sunday' => 0, 'monday' => 6, 'tuesday' => 6, 'wednesday' => 6, 'thursday' => 6, 'friday' => 6, 'saturday' => 6];
    expect(fn () => InternshipWorkSchedule::factory()->create(['weekly_hours' => $weeklyExcess]))
        ->toThrow(ValidationException::class);

    $zeroHours = array_fill_keys(array_keys($weeklyExcess), 0);
    expect(fn () => InternshipWorkSchedule::factory()->create(['weekly_hours' => $zeroHours]))
        ->toThrow(ValidationException::class);
});

test('permits one closure then a contiguous new period while preserving old hours', function () {
    $first = InternshipWorkSchedule::factory()->create();
    $end = $first->starts_on->copy()->addDays(9);
    $first->update(['ends_on' => $end]);
    expect(fn () => $first->update(['weekly_hours' => array_fill_keys(array_keys($first->weekly_hours), 3)]))
        ->toThrow(ValidationException::class);
    expect(fn () => $first->update(['ends_on' => $end->copy()->addDay()]))
        ->toThrow(ValidationException::class);

    $second = InternshipWorkSchedule::factory()->create([
        'internship_id' => $first->internship_id,
        'starts_on' => $end->copy()->addDay(),
    ]);
    expect($second->internship_id)->toBe($first->internship_id);

    expect(fn () => InternshipWorkSchedule::factory()->create([
        'internship_id' => $first->internship_id,
        'starts_on' => $end->copy()->addDays(3),
    ]))->toThrow(ValidationException::class);
});

test('does not allow inserting a gap before an existing later schedule', function () {
    $later = InternshipWorkSchedule::factory()->create();

    expect(fn () => InternshipWorkSchedule::factory()->create([
        'internship_id' => $later->internship_id,
        'starts_on' => $later->starts_on->copy()->subDays(10),
        'ends_on' => $later->starts_on->copy()->subDays(5),
    ]))->toThrow(ValidationException::class);
});

test('restricts parent deletion and rolls back the work schedule migration', function () {
    $schedule = InternshipWorkSchedule::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('generated_documents')->where('id', $schedule->generated_document_id)->delete()))
        ->toThrow(QueryException::class);
    $path = glob(database_path('migrations/*_create_internship_work_schedules_table.php'))[0];
    $batch = DB::table('migrations')->where('migration', pathinfo($path, PATHINFO_FILENAME))->value('batch');
    $this->artisan('migrate:rollback', ['--path' => [$path], '--realpath' => true, '--batch' => $batch, '--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('internship_work_schedules'))->toBeFalse();
    $this->artisan('migrate', ['--path' => [$path], '--realpath' => true, '--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('internship_work_schedules'))->toBeTrue();
});
