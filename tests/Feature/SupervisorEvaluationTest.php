<?php

use App\Enums\EvaluationStatus;
use App\Models\Affiliation;
use App\Models\Internship;
use App\Models\SupervisorEvaluation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

test('creates the PostgreSQL evaluation columns and stage references', function () {
    expect(DB::getDriverName())->toBe('pgsql');

    $columns = collect(Schema::getColumns('supervisor_evaluations'))->keyBy('name');
    expect($columns->keys()->all())->toBe([
        'id', 'internship_id', 'supervisor_affiliation_id', 'status',
        'has_academic_background', 'training_course', 'education_level', 'job_role',
        'experience_time', 'hours_requirement_met', 'estimated_hours_remaining',
        'performance', 'comprehension', 'technical_knowledge', 'organization',
        'initiative', 'attendance', 'discipline', 'sociability', 'cooperation',
        'responsibility', 'considerations', 'suggestions_to_institution',
        'performance_issues', 'other_observations', 'submitted_at', 'reviewed_at',
        'review_notes', 'cancelled_at', 'cancellation_reason', 'created_at', 'updated_at',
    ])->and($columns->get('has_academic_background'))->toMatchArray(['type' => 'boolean', 'nullable' => true])
        ->and($columns->get('estimated_hours_remaining'))->toMatchArray(['type' => 'smallint', 'nullable' => true])
        ->and($columns->get('performance'))->toMatchArray(['type' => 'character varying(255)', 'nullable' => true])
        ->and($columns->get('considerations'))->toMatchArray(['type' => 'text', 'nullable' => true])
        ->and(Schema::hasColumn('supervisor_evaluations', 'response'))->toBeFalse()
        ->and(Schema::hasColumn('supervisor_evaluations', 'form_version'))->toBeFalse()
        ->and(Schema::hasColumn('supervisor_evaluations', 'reviewed_by_affiliation_id'))->toBeFalse();

    foreach (['evaluation_released_at', 'evaluation_released_by_affiliation_id', 'current_supervisor_evaluation_id'] as $column) {
        expect(Schema::hasColumn('internships', $column))->toBeTrue();
    }

    $foreignKeys = collect(Schema::getForeignKeys('supervisor_evaluations'));
    foreach (['internship_id' => 'internships', 'supervisor_affiliation_id' => 'affiliations'] as $column => $table) {
        expect($foreignKeys->firstWhere('columns', [$column]))->toMatchArray([
            'foreign_table' => $table, 'foreign_columns' => ['id'], 'on_delete' => 'restrict',
        ]);
    }

    $stageKeys = collect(Schema::getForeignKeys('internships'));
    expect($stageKeys->firstWhere('columns', ['current_supervisor_evaluation_id']))->toMatchArray([
        'foreign_table' => 'supervisor_evaluations', 'on_delete' => 'restrict',
    ])->and($stageKeys->firstWhere('columns', ['evaluation_released_by_affiliation_id']))->toMatchArray([
        'foreign_table' => 'affiliations', 'on_delete' => 'restrict',
    ]);
});

test('keeps one form per stage and supervisor and restricts referenced deletion', function () {
    $evaluation = SupervisorEvaluation::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('internships')->where('id', $evaluation->internship_id)->delete()))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('affiliations')->where('id', $evaluation->supervisor_affiliation_id)->delete()))
        ->toThrow(QueryException::class);
    expect(fn () => $evaluation->delete())->toThrow(ValidationException::class);

    expect(fn () => DB::transaction(fn () => SupervisorEvaluation::factory()->create([
        'internship_id' => $evaluation->internship_id,
        'supervisor_affiliation_id' => $evaluation->supervisor_affiliation_id,
    ])))->toThrow(QueryException::class);
});

test('casts status and booleans and follows the stage and supervisor references', function () {
    $evaluation = SupervisorEvaluation::factory()->submitted()->create()->fresh();

    expect($evaluation->status)->toBe(EvaluationStatus::Submitted)
        ->and($evaluation->has_academic_background)->toBeTrue()
        ->and($evaluation->hours_requirement_met)->toBeTrue()
        ->and($evaluation->performance)->toBe('good')
        ->and($evaluation->submitted_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($evaluation->internship->id)->toBe($evaluation->internship_id)
        ->and($evaluation->supervisorAffiliation->id)->toBe($evaluation->internship->supervisor_affiliation_id)
        ->and($evaluation->internship->supervisorEvaluations)->toHaveCount(1)
        ->and($evaluation->supervisorAffiliation->supervisorEvaluations)->toHaveCount(1);
});

test('preserves the answering supervisor if the internship supervisor changes', function () {
    $evaluation = SupervisorEvaluation::factory()->submitted()->create();
    $originalSupervisorId = $evaluation->supervisor_affiliation_id;
    $newSupervisor = Affiliation::factory()->supervisor()->create();
    $evaluation->internship->update(['supervisor_affiliation_id' => $newSupervisor->id]);

    expect($evaluation->fresh()->supervisor_affiliation_id)->toBe($originalSupervisorId)
        ->and($evaluation->fresh()->supervisorAffiliation->id)->toBe($originalSupervisorId);

    $second = SupervisorEvaluation::factory()->create([
        'internship_id' => $evaluation->internship_id,
        'supervisor_affiliation_id' => $newSupervisor->id,
    ]);
    expect($second->id)->not->toBe($evaluation->id);
});

test('allows a partial draft and requires every answer at submission', function () {
    $evaluation = SupervisorEvaluation::factory()->create([
        'job_role' => 'Gerente',
        'performance' => 'good',
    ]);

    expect($evaluation->status)->toBe(EvaluationStatus::Draft)
        ->and($evaluation->hours_requirement_met)->toBeNull();

    expect(fn () => $evaluation->update(['status' => EvaluationStatus::Submitted, 'submitted_at' => now()]))
        ->toThrow(ValidationException::class);

    $valid = SupervisorEvaluation::factory()->submitted()->make([
        'internship_id' => $evaluation->internship_id,
        'supervisor_affiliation_id' => $evaluation->supervisor_affiliation_id,
    ]);
    $evaluation->fill($valid->getAttributes())->save();

    expect($evaluation->fresh()->status)->toBe(EvaluationStatus::Submitted);
});

test('requires only the selected qualification branch and validates concepts', function () {
    $evaluation = SupervisorEvaluation::factory()->submitted()->make([
        'has_academic_background' => false,
        'training_course' => null,
        'education_level' => null,
        'experience_time' => null,
    ]);

    expect(fn () => $evaluation->save())->toThrow(ValidationException::class);

    $evaluation->experience_time = '5 anos';
    $evaluation->save();
    expect($evaluation->fresh()->experience_time)->toBe('5 anos')
        ->and($evaluation->fresh()->has_academic_background)->toBeFalse();

    expect(fn () => SupervisorEvaluation::factory()->submitted()->create(['performance' => 'invalid']))
        ->toThrow(ValidationException::class);
    expect(fn () => SupervisorEvaluation::factory()->submitted()->create(['training_course' => null]))
        ->toThrow(ValidationException::class);
});

test('freezes a submitted answer and reopens the same form after return', function () {
    $evaluation = SupervisorEvaluation::factory()->submitted()->create();

    expect(fn () => $evaluation->update(['performance' => 'excellent']))
        ->toThrow(ValidationException::class);

    $evaluation->refresh();
    $evaluation->update([
        'status' => EvaluationStatus::Returned,
        'reviewed_at' => now(),
        'review_notes' => 'Rever desempenho.',
    ]);

    $evaluation->update(['performance' => 'excellent']);
    expect($evaluation->fresh()->performance)->toBe('excellent');

    expect(fn () => $evaluation->update(['status' => EvaluationStatus::Submitted]))
        ->toThrow(ValidationException::class);
    $evaluation->refresh();
    $evaluation->update(['status' => EvaluationStatus::Submitted, 'submitted_at' => now()->addMinute()]);
    expect($evaluation->fresh()->status)->toBe(EvaluationStatus::Submitted)
        ->and(SupervisorEvaluation::where('internship_id', $evaluation->internship_id)->count())->toBe(1);
});

test('stores optional comments in their own nullable columns', function () {
    $evaluation = SupervisorEvaluation::factory()->submitted()->create([
        'considerations' => 'Bom desempenho.',
        'suggestions_to_institution' => null,
    ])->fresh();

    expect($evaluation->considerations)->toBe('Bom desempenho.')
        ->and($evaluation->suggestions_to_institution)->toBeNull()
        ->and($evaluation->performance_issues)->toBeNull()
        ->and($evaluation->other_observations)->toBeNull();
});

test('requires positive remaining hours and prevents approval before the workload is met', function () {
    expect(fn () => SupervisorEvaluation::factory()->submitted()->create([
        'hours_requirement_met' => false,
    ]))->toThrow(ValidationException::class);

    $evaluation = SupervisorEvaluation::factory()->submitted()->create([
        'hours_requirement_met' => false,
        'estimated_hours_remaining' => 20,
    ]);

    expect(fn () => $evaluation->update([
        'status' => EvaluationStatus::Approved,
        'reviewed_at' => now(),
    ]))->toThrow(ValidationException::class);
});

test('requires review notes on return and cancellation reason when cancelled', function () {
    $evaluation = SupervisorEvaluation::factory()->submitted()->create();
    expect(fn () => $evaluation->update(['status' => EvaluationStatus::Returned]))
        ->toThrow(ValidationException::class);

    $evaluation->update([
        'status' => EvaluationStatus::Returned,
        'reviewed_at' => now(),
        'review_notes' => 'Revisar resposta.',
    ]);

    expect($evaluation->fresh()->review_notes)->toBe('Revisar resposta.')
        ->and(Activity::forSubject($evaluation)->count())->toBe(2);

    expect(fn () => $evaluation->update(['status' => EvaluationStatus::Cancelled, 'cancelled_at' => now()]))
        ->toThrow(ValidationException::class);
    $evaluation->update(['status' => EvaluationStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => 'Estágio encerrado.']);
    expect($evaluation->fresh()->status)->toBe(EvaluationStatus::Cancelled);
});

test('references only an approved evaluation from the same stage', function () {
    $evaluation = SupervisorEvaluation::factory()->submitted()->create();
    $internship = $evaluation->internship;
    $other = Internship::factory()->create();

    expect(fn () => $internship->update(['current_supervisor_evaluation_id' => $evaluation->id]))
        ->toThrow(ValidationException::class);

    $evaluation->update([
        'status' => EvaluationStatus::Approved,
        'reviewed_at' => now(),
    ]);

    expect(fn () => $other->update(['current_supervisor_evaluation_id' => $evaluation->id]))
        ->toThrow(ValidationException::class);

    $internship->update(['current_supervisor_evaluation_id' => $evaluation->id]);
    expect($internship->fresh()->currentSupervisorEvaluation->id)->toBe($evaluation->id);

    expect(fn () => $evaluation->update(['review_notes' => 'Alteração tardia']))
        ->toThrow(ValidationException::class);
});

test('records evaluation release only with an internship office affiliation', function () {
    $internship = Internship::factory()->create();
    $student = Affiliation::factory()->student()->create();

    expect(fn () => $internship->update([
        'evaluation_released_at' => now(),
        'evaluation_released_by_affiliation_id' => $student->id,
    ]))->toThrow(ValidationException::class);

    $office = Affiliation::factory()->server()->create();
    $internship->update([
        'evaluation_released_at' => now(),
        'evaluation_released_by_affiliation_id' => $office->id,
    ]);

    expect($internship->fresh()->evaluation_released_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($internship->fresh()->evaluationReleasedByAffiliation->id)->toBe($office->id);
});

test('rolls back and reapplies the evaluation migration in the foreign key order', function () {
    $path = glob(database_path('migrations/*_create_supervisor_evaluations_table.php'))[0];
    $batch = DB::table('migrations')->where('migration', pathinfo($path, PATHINFO_FILENAME))->value('batch');

    $this->artisan('migrate:rollback', [
        '--path' => [$path], '--realpath' => true, '--batch' => $batch, '--no-interaction' => true,
    ])->assertSuccessful();

    expect(Schema::hasTable('supervisor_evaluations'))->toBeFalse()
        ->and(Schema::hasColumn('internships', 'current_supervisor_evaluation_id'))->toBeFalse();

    $this->artisan('migrate', [
        '--path' => [$path], '--realpath' => true, '--no-interaction' => true,
    ])->assertSuccessful();

    expect(Schema::hasTable('supervisor_evaluations'))->toBeTrue();
});
