<?php

use App\Enums\GeneratedDocumentOrigin;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Models\GeneratedDocument;
use App\Models\TemplateVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

test('creates the PostgreSQL generated documents schema with restricted references and no stored output file', function () {
    expect(DB::getDriverName())->toBe('pgsql');
    $columns = collect(Schema::getColumns('generated_documents'))->keyBy('name');
    expect($columns->keys()->all())->toBe([
        'id', 'internship_id', 'template_version_id', 'origin', 'type', 'status',
        'signature_availability_location', 'snapshot', 'generation_token',
        'output_filename', 'template_sha256', 'generated_at', 'cancelled_at',
        'cancellation_reason', 'created_at', 'updated_at',
    ])->and($columns->get('snapshot'))->toMatchArray(['type' => 'jsonb', 'nullable' => true])
        ->and($columns->has('generated_by_affiliation_id'))->toBeFalse()
        ->and($columns->has('cancelled_by_affiliation_id'))->toBeFalse()
        ->and($columns->has('file_path'))->toBeFalse();

    $keys = collect(Schema::getForeignKeys('generated_documents'));
    expect($keys->firstWhere('columns', ['internship_id']))->toMatchArray(['foreign_table' => 'internships', 'on_delete' => 'restrict'])
        ->and($keys->firstWhere('columns', ['template_version_id']))->toMatchArray(['foreign_table' => 'template_versions', 'on_delete' => 'restrict'])
        ->and(collect(Schema::getIndexes('generated_documents'))->firstWhere('columns', ['generation_token']))->toMatchArray(['unique' => true]);
});

test('casts and preserves an SGE generation and the referenced template version', function () {
    $document = GeneratedDocument::factory()->create()->fresh();
    expect($document->origin)->toBe(GeneratedDocumentOrigin::SGE)
        ->and($document->type)->toBe(GeneratedDocumentType::Main)
        ->and($document->status)->toBe(GeneratedDocumentStatus::Generated)
        ->and($document->snapshot)->toBeArray()
        ->and($document->generated_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($document->templateVersion->id)->toBe($document->template_version_id)
        ->and($document->internship->generatedDocuments)->toHaveCount(1)
        ->and($document->templateVersion->generatedDocuments)->toHaveCount(1)
        ->and(Activity::forSubject($document)->count())->toBe(1);

    expect(fn () => DB::transaction(fn () => TemplateVersion::findOrFail($document->template_version_id)->delete()))
        ->toThrow(QueryException::class);
    expect(fn () => $document->delete())->toThrow(ValidationException::class);
});

test('requires a validated template and matching hash for an SGE generation', function () {
    expect(fn () => GeneratedDocument::factory()->create([
        'template_version_id' => null,
        'template_sha256' => str_repeat('a', 64),
    ]))
        ->toThrow(ValidationException::class);
    expect(fn () => GeneratedDocument::factory()->create(['template_sha256' => str_repeat('a', 64)]))
        ->toThrow(ValidationException::class);

    $unvalidated = TemplateVersion::factory()->create();
    expect(fn () => GeneratedDocument::factory()->create([
        'template_version_id' => $unvalidated->id,
        'template_sha256' => $unvalidated->file_sha256,
    ]))->toThrow(ValidationException::class);
});

test('records an external document without template or generation snapshot', function () {
    $document = GeneratedDocument::factory()->fromGrantingParty()->create()->fresh();
    expect($document->origin)->toBe(GeneratedDocumentOrigin::GrantingParty)
        ->and($document->template_version_id)->toBeNull()
        ->and($document->snapshot)->toBeNull()
        ->and($document->output_filename)->toBeNull();

    expect(fn () => GeneratedDocument::factory()->fromGrantingParty()->create(['snapshot' => ['wrong' => true]]))
        ->toThrow(ValidationException::class);
});

test('requires a signature location and cancellation details and protects the generation snapshot', function () {
    $document = GeneratedDocument::factory()->create();
    expect(fn () => $document->update(['status' => GeneratedDocumentStatus::AwaitingSignature]))
        ->toThrow(ValidationException::class);

    $document->update(['status' => GeneratedDocumentStatus::AwaitingSignature, 'signature_availability_location' => 'SIPAC']);
    expect($document->fresh()->signature_availability_location)->toBe('SIPAC');

    expect(fn () => $document->update(['status' => GeneratedDocumentStatus::Cancelled]))
        ->toThrow(ValidationException::class);
    $document->update(['status' => GeneratedDocumentStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => 'Erro identificado.']);
    expect($document->fresh()->status)->toBe(GeneratedDocumentStatus::Cancelled);

    expect(fn () => $document->update(['snapshot' => ['changed' => true]]))->toThrow(ValidationException::class);
    expect(fn () => GeneratedDocument::factory()->create([
        'type' => GeneratedDocumentType::OrientationCertificate,
        'status' => GeneratedDocumentStatus::AwaitingSignature,
        'signature_availability_location' => 'Portal',
    ]))->toThrow(ValidationException::class);
});

test('rolls back generated documents after dependent schedules and reapplies both migrations', function () {
    $documentPath = glob(database_path('migrations/*_create_generated_documents_table.php'))[0];
    $schedulePath = glob(database_path('migrations/*_create_internship_work_schedules_table.php'))[0];
    $batch = DB::table('migrations')->where('migration', pathinfo($documentPath, PATHINFO_FILENAME))->value('batch');
    foreach ([$schedulePath, $documentPath] as $path) {
        $this->artisan('migrate:rollback', ['--path' => [$path], '--realpath' => true, '--batch' => $batch, '--no-interaction' => true])->assertSuccessful();
    }
    expect(Schema::hasTable('generated_documents'))->toBeFalse();
    foreach ([$documentPath, $schedulePath] as $path) {
        $this->artisan('migrate', ['--path' => [$path], '--realpath' => true, '--no-interaction' => true])->assertSuccessful();
    }
    expect(Schema::hasTable('generated_documents'))->toBeTrue();
});
