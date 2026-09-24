<?php

use App\Enums\EmancipationEvidenceStatus;
use App\Enums\LegalCapacityDeclaration;
use App\Models\EmancipationEvidence;
use App\Models\InternshipRequest;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;

test('creates the evidence schema without redundant author or reviewer columns', function () {
    expect(DB::getDriverName())->toBe('pgsql');
    $columns = collect(Schema::getColumns('emancipation_evidences'))->keyBy('name');
    expect($columns->keys()->all())->toBe([
        'id', 'internship_request_id', 'status', 'reviewed_at', 'return_reason', 'created_at', 'updated_at',
    ])->and($columns->has('submitted_by_affiliation_id'))->toBeFalse()
        ->and($columns->has('reviewed_by_affiliation_id'))->toBeFalse();
    expect(collect(Schema::getForeignKeys('emancipation_evidences'))->firstWhere('columns', ['internship_request_id']))
        ->toMatchArray(['foreign_table' => 'internship_requests', 'on_delete' => 'restrict']);
});

test('keeps each proof in a private media collection without logging file metadata', function () {
    Storage::fake('local');
    $request = InternshipRequest::factory()->create();
    $first = EmancipationEvidence::factory()->create(['internship_request_id' => $request->id]);
    $first->addMedia(UploadedFile::fake()->createWithContent('proof.pdf', '%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj'))
        ->toMediaCollection('emancipation_evidence');
    $second = EmancipationEvidence::factory()->create(['internship_request_id' => $request->id]);
    $second->addMedia(UploadedFile::fake()->createWithContent('new-proof.pdf', '%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj'))
        ->toMediaCollection('emancipation_evidence');

    expect($first->getMedia('emancipation_evidence'))->toHaveCount(1)
        ->and($second->getMedia('emancipation_evidence'))->toHaveCount(1)
        ->and($first->getFirstMedia('emancipation_evidence')->disk)->toBe('local')
        ->and($request->emancipationEvidences)->toHaveCount(2)
        ->and(Activity::forSubject($first)->first()->attribute_changes->toJson())->not->toContain('proof.pdf');

    expect(fn () => $first->addMedia(UploadedFile::fake()->createWithContent('replacement.pdf', '%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj'))
        ->toMediaCollection('emancipation_evidence'))->toThrow(FileUnacceptableForCollection::class);
    expect(fn () => $first->delete())->toThrow(ValidationException::class);
});

test('requires the private proof and review timestamp before approval or return', function () {
    Storage::fake('local');
    $evidence = EmancipationEvidence::factory()->create();
    expect(fn () => $evidence->update(['status' => EmancipationEvidenceStatus::Approved, 'reviewed_at' => now()]))
        ->toThrow(ValidationException::class);

    $evidence->addMedia(UploadedFile::fake()->createWithContent('proof.pdf', '%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj'))
        ->toMediaCollection('emancipation_evidence');
    expect(fn () => $evidence->update(['status' => EmancipationEvidenceStatus::Returned, 'reviewed_at' => now()]))
        ->toThrow(ValidationException::class);
    $evidence->update(['status' => EmancipationEvidenceStatus::Returned, 'reviewed_at' => now(), 'return_reason' => 'Documento ilegível.']);
    expect($evidence->fresh()->status)->toBe(EmancipationEvidenceStatus::Returned);
});

test('requires a proof for the emancipated branch of a submitted request', function () {
    Storage::fake('local');
    $request = InternshipRequest::factory()->submitted()->create();
    $emancipated = [
        'legal_capacity_declaration' => LegalCapacityDeclaration::EmancipatedMinor,
        'legal_guardian_name' => null,
        'legal_guardian_cpf' => null,
        'legal_guardian_kinship' => null,
        'legal_guardian_email' => null,
    ];
    expect(fn () => $request->update($emancipated))->toThrow(ValidationException::class);

    $evidence = EmancipationEvidence::factory()->create(['internship_request_id' => $request->id]);
    $evidence->addMedia(UploadedFile::fake()->createWithContent('proof.pdf', '%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj'))
        ->toMediaCollection('emancipation_evidence');
    $request->update($emancipated);
    expect($request->fresh()->legal_capacity_declaration)->toBe(LegalCapacityDeclaration::EmancipatedMinor);
});

test('restricts parent deletion and rolls back the evidence migration', function () {
    $evidence = EmancipationEvidence::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('internship_requests')->where('id', $evidence->internship_request_id)->delete()))
        ->toThrow(QueryException::class);

    $path = glob(database_path('migrations/*_create_emancipation_evidences_table.php'))[0];
    $batch = DB::table('migrations')->where('migration', pathinfo($path, PATHINFO_FILENAME))->value('batch');
    $this->artisan('migrate:rollback', ['--path' => [$path], '--realpath' => true, '--batch' => $batch, '--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('emancipation_evidences'))->toBeFalse();
    $this->artisan('migrate', ['--path' => [$path], '--realpath' => true, '--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('emancipation_evidences'))->toBeTrue();
});
