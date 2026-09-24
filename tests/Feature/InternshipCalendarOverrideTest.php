<?php

use App\Models\InternshipCalendarOverride;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

test('creates a PostgreSQL calendar override with a restricted stage FK and unique date per stage', function () {
    expect(DB::getDriverName())->toBe('pgsql');
    $columns = collect(Schema::getColumns('internship_calendar_overrides'))->keyBy('name');
    expect($columns->keys()->all())->toBe([
        'id', 'internship_id', 'date', 'is_working_day', 'reason', 'created_at', 'updated_at',
    ])->and($columns->get('date'))->toMatchArray(['type' => 'date', 'nullable' => false])
        ->and($columns->get('is_working_day'))->toMatchArray(['type' => 'boolean', 'nullable' => false])
        ->and($columns->has('created_by_affiliation_id'))->toBeFalse();
    expect(collect(Schema::getForeignKeys('internship_calendar_overrides'))->firstWhere('columns', ['internship_id']))
        ->toMatchArray(['foreign_table' => 'internships', 'on_delete' => 'restrict'])
        ->and(collect(Schema::getIndexes('internship_calendar_overrides'))->firstWhere('columns', ['internship_id', 'date']))
        ->toMatchArray(['unique' => true]);
});

test('allows opposite decisions on different dates and records changes in the activity log', function () {
    $override = InternshipCalendarOverride::factory()->create()->fresh();
    expect($override->is_working_day)->toBeFalse()
        ->and($override->date)->toBeInstanceOf(DateTimeInterface::class)
        ->and($override->internship->calendarOverrides)->toHaveCount(1)
        ->and(Activity::forSubject($override)->count())->toBe(1);

    $override->update(['is_working_day' => true, 'reason' => 'Expediente autorizado.']);
    expect($override->fresh()->is_working_day)->toBeTrue()
        ->and(Activity::forSubject($override)->count())->toBe(2);
    expect(fn () => $override->delete())->toThrow(ValidationException::class);
});

test('rejects duplicate dates within one stage while allowing the same date in another stage', function () {
    $override = InternshipCalendarOverride::factory()->create();
    expect(fn () => InternshipCalendarOverride::factory()->create([
        'internship_id' => $override->internship_id,
        'date' => $override->date,
    ]))->toThrow(ValidationException::class);
    InternshipCalendarOverride::factory()->create(['date' => $override->date]);

    expect(fn () => DB::transaction(fn () => DB::table('internship_calendar_overrides')->insert([
        'internship_id' => $override->internship_id,
        'date' => $override->date,
        'is_working_day' => true,
        'reason' => 'Duplicado',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);
    expect(fn () => InternshipCalendarOverride::factory()->create(['reason' => '  ']))
        ->toThrow(ValidationException::class);
});

test('restricts stage deletion and rolls back the calendar override migration', function () {
    $override = InternshipCalendarOverride::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('internships')->where('id', $override->internship_id)->delete()))
        ->toThrow(QueryException::class);
    $path = glob(database_path('migrations/*_create_internship_calendar_overrides_table.php'))[0];
    $batch = DB::table('migrations')->where('migration', pathinfo($path, PATHINFO_FILENAME))->value('batch');
    $this->artisan('migrate:rollback', ['--path' => [$path], '--realpath' => true, '--batch' => $batch, '--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('internship_calendar_overrides'))->toBeFalse();
    $this->artisan('migrate', ['--path' => [$path], '--realpath' => true, '--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('internship_calendar_overrides'))->toBeTrue();
});
