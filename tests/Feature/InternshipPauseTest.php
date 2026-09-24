<?php

use App\Enums\InternshipStatus;
use App\Models\Internship;
use App\Models\InternshipPause;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

test('creates the PostgreSQL internship pauses schema and restricted stage reference', function () {
    expect(DB::getDriverName())->toBe('pgsql');
    $columns = collect(Schema::getColumns('internship_pauses'))->keyBy('name');
    expect($columns->keys()->all())->toBe(['id', 'internship_id', 'starts_at', 'ends_at', 'reason', 'created_at', 'updated_at'])
        ->and($columns->get('starts_at'))->toMatchArray(['type' => 'date', 'nullable' => false])
        ->and($columns->get('ends_at'))->toMatchArray(['type' => 'date', 'nullable' => false]);
    expect(collect(Schema::getForeignKeys('internship_pauses'))->firstWhere('columns', ['internship_id']))
        ->toMatchArray(['foreign_table' => 'internships', 'on_delete' => 'restrict']);
});

test('creates a pause only for an internship in progress and records its lifecycle', function () {
    $pause = InternshipPause::factory()->create()->fresh();
    expect($pause->internship->status)->toBe(InternshipStatus::InProgress)
        ->and($pause->starts_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($pause->ends_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($pause->internship->pauses)->toHaveCount(1)
        ->and(Activity::forSubject($pause)->count())->toBe(1);

    $pause->update(['reason' => 'Pausa corrigida.']);
    $pause->delete();
    expect(Activity::forSubject($pause)->count())->toBe(3);
});

test('rejects inactive stage, inverted dates, dates before the start and inclusive overlaps', function () {
    $inactive = Internship::factory()->create();
    expect(fn () => InternshipPause::factory()->create(['internship_id' => $inactive->id]))
        ->toThrow(ValidationException::class);

    $pause = InternshipPause::factory()->create();
    expect(fn () => InternshipPause::factory()->create([
        'internship_id' => $pause->internship_id,
        'starts_at' => $pause->ends_at->toDateString(),
        'ends_at' => $pause->ends_at->copy()->addDay()->toDateString(),
    ]))->toThrow(ValidationException::class);
    expect(fn () => $pause->update(['ends_at' => $pause->starts_at->copy()->subDay()]))
        ->toThrow(ValidationException::class);
    expect(fn () => InternshipPause::factory()->create([
        'internship_id' => $pause->internship_id,
        'starts_at' => $pause->internship->planned_start_date->copy()->subDay()->toDateString(),
    ]))->toThrow(ValidationException::class);
});

test('restricts deletion of a referenced internship and reverses the pause migration', function () {
    $pause = InternshipPause::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('internships')->where('id', $pause->internship_id)->delete()))
        ->toThrow(QueryException::class);

    $path = glob(database_path('migrations/*_create_internship_pauses_table.php'))[0];
    $batch = DB::table('migrations')->where('migration', pathinfo($path, PATHINFO_FILENAME))->value('batch');
    $this->artisan('migrate:rollback', ['--path' => [$path], '--realpath' => true, '--batch' => $batch, '--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('internship_pauses'))->toBeFalse();
    $this->artisan('migrate', ['--path' => [$path], '--realpath' => true, '--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('internship_pauses'))->toBeTrue();
});
