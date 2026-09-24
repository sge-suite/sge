<?php

use App\Enums\InternshipCancellationRequestStatus;
use App\Enums\InternshipStatus;
use App\Models\Internship;
use App\Models\InternshipCancellationRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

test('creates the cancellation request schema with a restricted internship FK and no audit actor columns', function () {
    expect(DB::getDriverName())->toBe('pgsql');
    $columns = collect(Schema::getColumns('internship_cancellation_requests'))->keyBy('name');
    expect($columns->keys()->all())->toBe([
        'id', 'internship_id', 'reason', 'status', 'reviewed_at', 'decision_reason',
        'effective_date', 'created_at', 'updated_at',
    ])->and($columns->get('effective_date'))->toMatchArray(['type' => 'date', 'nullable' => true])
        ->and($columns->has('requested_by_affiliation_id'))->toBeFalse()
        ->and($columns->has('reviewed_by_affiliation_id'))->toBeFalse();
    expect(collect(Schema::getForeignKeys('internship_cancellation_requests'))->firstWhere('columns', ['internship_id']))
        ->toMatchArray(['foreign_table' => 'internships', 'on_delete' => 'restrict']);
});

test('records a request and prevents another pending request for the same internship', function () {
    $request = InternshipCancellationRequest::factory()->create()->fresh();
    expect($request->status)->toBe(InternshipCancellationRequestStatus::Submitted)
        ->and($request->internship->cancellationRequests)->toHaveCount(1)
        ->and(Activity::forSubject($request)->count())->toBe(1);
    expect(fn () => InternshipCancellationRequest::factory()->create(['internship_id' => $request->internship_id]))
        ->toThrow(ValidationException::class);
    expect(fn () => $request->delete())->toThrow(ValidationException::class);
});

test('validates rejection and approval details and preserves final requests', function () {
    $request = InternshipCancellationRequest::factory()->create();
    expect(fn () => $request->update(['status' => InternshipCancellationRequestStatus::Rejected, 'reviewed_at' => now()]))
        ->toThrow(ValidationException::class);
    $request->update(['status' => InternshipCancellationRequestStatus::Rejected, 'reviewed_at' => now(), 'decision_reason' => 'Motivo insuficiente.']);
    expect($request->fresh()->status)->toBe(InternshipCancellationRequestStatus::Rejected);

    $second = InternshipCancellationRequest::factory()->create(['internship_id' => $request->internship_id]);
    expect(fn () => $second->update(['status' => InternshipCancellationRequestStatus::Approved, 'reviewed_at' => now()]))
        ->toThrow(ValidationException::class);
    $second->update(['status' => InternshipCancellationRequestStatus::Approved, 'reviewed_at' => now(), 'effective_date' => today()]);
    expect($second->fresh()->effective_date)->toBeInstanceOf(DateTimeInterface::class);
});

test('rejects a blank reason and a new request for a completed internship', function () {
    expect(fn () => InternshipCancellationRequest::factory()->create(['reason' => '  ']))
        ->toThrow(ValidationException::class);
    $completed = Internship::factory()->create(['status' => InternshipStatus::Completed]);
    expect(fn () => InternshipCancellationRequest::factory()->create(['internship_id' => $completed->id]))
        ->toThrow(ValidationException::class);
});

test('restricts stage deletion and rolls back the cancellation request migration', function () {
    $request = InternshipCancellationRequest::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('internships')->where('id', $request->internship_id)->delete()))
        ->toThrow(QueryException::class);
    $path = glob(database_path('migrations/*_create_internship_cancellation_requests_table.php'))[0];
    $batch = DB::table('migrations')->where('migration', pathinfo($path, PATHINFO_FILENAME))->value('batch');
    $this->artisan('migrate:rollback', ['--path' => [$path], '--realpath' => true, '--batch' => $batch, '--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('internship_cancellation_requests'))->toBeFalse();
    $this->artisan('migrate', ['--path' => [$path], '--realpath' => true, '--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('internship_cancellation_requests'))->toBeTrue();
});
