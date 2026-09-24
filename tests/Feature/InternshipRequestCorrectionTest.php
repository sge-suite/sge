<?php

use App\Enums\InternshipRequestCorrectionStatus;
use App\Models\InternshipRequestCorrection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

test('creates the PostgreSQL correction schema with JSONB sections and a restricted request FK', function () {
    expect(DB::getDriverName())->toBe('pgsql');
    $columns = collect(Schema::getColumns('internship_request_corrections'))->keyBy('name');
    expect($columns->keys()->all())->toBe([
        'id', 'internship_request_id', 'message', 'affected_sections', 'status',
        'responded_at', 'resolved_at', 'created_at', 'updated_at',
    ])->and($columns->get('affected_sections'))->toMatchArray(['type' => 'jsonb', 'nullable' => false])
        ->and($columns->has('requested_by_affiliation_id'))->toBeFalse();
    expect(collect(Schema::getForeignKeys('internship_request_corrections'))->firstWhere('columns', ['internship_request_id']))
        ->toMatchArray(['foreign_table' => 'internship_requests', 'on_delete' => 'restrict']);
});

test('stores one open correction with editable section codes and activity history', function () {
    $correction = InternshipRequestCorrection::factory()->create()->fresh();
    expect($correction->status)->toBe(InternshipRequestCorrectionStatus::Open)
        ->and($correction->affected_sections)->toBe(['schedule'])
        ->and($correction->internshipRequest->corrections)->toHaveCount(1)
        ->and(Activity::forSubject($correction)->count())->toBe(1);

    expect(fn () => InternshipRequestCorrection::factory()->create(['internship_request_id' => $correction->internship_request_id]))
        ->toThrow(ValidationException::class);
    expect(fn () => $correction->delete())->toThrow(ValidationException::class);
});

test('requires message and unique stable section names', function () {
    expect(fn () => InternshipRequestCorrection::factory()->create(['message' => '  ']))
        ->toThrow(ValidationException::class);
    expect(fn () => InternshipRequestCorrection::factory()->create(['affected_sections' => []]))
        ->toThrow(ValidationException::class);
    expect(fn () => InternshipRequestCorrection::factory()->create(['affected_sections' => ['schedule', 'schedule']]))
        ->toThrow(ValidationException::class);
    expect(fn () => InternshipRequestCorrection::factory()->create(['affected_sections' => ['Schedule!']]))
        ->toThrow(ValidationException::class);
});

test('requires response and resolution timestamps for their respective states', function () {
    $correction = InternshipRequestCorrection::factory()->create();
    expect(fn () => $correction->update(['status' => InternshipRequestCorrectionStatus::Responded]))
        ->toThrow(ValidationException::class);
    $correction->update(['status' => InternshipRequestCorrectionStatus::Responded, 'responded_at' => now()]);
    expect(fn () => $correction->update(['status' => InternshipRequestCorrectionStatus::Resolved]))
        ->toThrow(ValidationException::class);
    $correction->update(['status' => InternshipRequestCorrectionStatus::Resolved, 'resolved_at' => now()]);
    expect($correction->fresh()->status)->toBe(InternshipRequestCorrectionStatus::Resolved);

    InternshipRequestCorrection::factory()->create(['internship_request_id' => $correction->internship_request_id]);
    expect($correction->internshipRequest->corrections()->count())->toBe(2);
});

test('restricts parent deletion and rolls back the correction migration', function () {
    $correction = InternshipRequestCorrection::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('internship_requests')->where('id', $correction->internship_request_id)->delete()))
        ->toThrow(QueryException::class);
    $path = glob(database_path('migrations/*_create_internship_request_corrections_table.php'))[0];
    $batch = DB::table('migrations')->where('migration', pathinfo($path, PATHINFO_FILENAME))->value('batch');
    $this->artisan('migrate:rollback', ['--path' => [$path], '--realpath' => true, '--batch' => $batch, '--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('internship_request_corrections'))->toBeFalse();
    $this->artisan('migrate', ['--path' => [$path], '--realpath' => true, '--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('internship_request_corrections'))->toBeTrue();
});
