<?php

use App\Models\Affiliation;
use App\Models\DocumentTemplate;
use App\Models\TemplateVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;

test('creates the template versions schema with JSONB, composite uniqueness and restricted foreign keys', function () {
    expect(DB::getDriverName())->toBe('pgsql');

    $columns = collect(Schema::getColumns('template_versions'))->keyBy('name');

    expect($columns->keys()->all())->toBe([
        'id', 'document_template_id', 'version', 'file_sha256', 'file_size',
        'required_variables', 'optional_variables', 'detected_variables',
        'validation_report', 'uploaded_by_affiliation_id', 'validated_at',
        'validated_by_affiliation_id', 'created_at', 'updated_at',
    ]);

    foreach ([
        'id' => ['bigint', false],
        'document_template_id' => ['bigint', false],
        'version' => ['integer', false],
        'file_sha256' => ['character varying(64)', false],
        'file_size' => ['bigint', false],
        'required_variables' => ['jsonb', false],
        'optional_variables' => ['jsonb', false],
        'detected_variables' => ['jsonb', true],
        'validation_report' => ['jsonb', true],
        'uploaded_by_affiliation_id' => ['bigint', false],
        'validated_at' => ['timestamp(0) without time zone', true],
        'validated_by_affiliation_id' => ['bigint', true],
        'created_at' => ['timestamp(0) without time zone', true],
        'updated_at' => ['timestamp(0) without time zone', true],
    ] as $name => [$type, $nullable]) {
        expect($columns->get($name))->toMatchArray(['type' => $type, 'nullable' => $nullable]);
    }

    $indexes = collect(Schema::getIndexes('template_versions'));
    $foreignKeys = collect(Schema::getForeignKeys('template_versions'));

    expect($indexes->firstWhere('primary', true))->toMatchArray(['columns' => ['id'], 'unique' => true])
        ->and($indexes->firstWhere('columns', ['document_template_id', 'version']))->toMatchArray(['unique' => true])
        ->and($indexes->firstWhere('columns', ['document_template_id', 'file_sha256']))->toMatchArray(['unique' => true])
        ->and($foreignKeys)->toHaveCount(3);

    foreach ([
        'document_template_id' => 'document_templates',
        'uploaded_by_affiliation_id' => 'affiliations',
        'validated_by_affiliation_id' => 'affiliations',
    ] as $column => $table) {
        expect($foreignKeys->firstWhere('columns', [$column]))->toMatchArray([
            'foreign_table' => $table, 'foreign_columns' => ['id'], 'on_delete' => 'restrict',
        ]);
    }

    expect(Schema::hasColumn('template_versions', 'file_path'))->toBeFalse()
        ->and(Schema::hasColumn('template_versions', 'deleted_at'))->toBeFalse();
});

test('rolls back and reapplies the template versions migration', function () {
    $path = glob(database_path('migrations/*_create_template_versions_table.php'))[0];
    $batch = DB::table('migrations')->where('migration', pathinfo($path, PATHINFO_FILENAME))->value('batch');

    $this->artisan('migrate:rollback', [
        '--path' => [$path], '--realpath' => true, '--batch' => $batch, '--no-interaction' => true,
    ])->assertSuccessful();

    expect(Schema::hasTable('template_versions'))->toBeFalse()
        ->and(Schema::hasTable('document_templates'))->toBeTrue();

    $this->artisan('migrate', [
        '--path' => [$path], '--realpath' => true, '--no-interaction' => true,
    ])->assertSuccessful();

    expect(Schema::hasTable('template_versions'))->toBeTrue();
});

test('casts version metadata and relates its template and responsible affiliations', function () {
    $template = DocumentTemplate::factory()->create();
    $uploader = Affiliation::factory()->server()->create();
    $reviewer = Affiliation::factory()->server()->create();
    $version = TemplateVersion::factory()->for($template, 'documentTemplate')->create([
        'uploaded_by_affiliation_id' => $uploader->id,
        'validated_by_affiliation_id' => $reviewer->id,
        'validated_at' => now(),
        'required_variables' => ['ESTUDANTE_NOME'],
        'optional_variables' => ['CURSO_NOME'],
        'detected_variables' => ['ESTUDANTE_NOME', 'CURSO_NOME'],
        'validation_report' => ['errors' => [], 'warnings' => []],
    ])->fresh();

    expect($version->version)->toBeInt()
        ->and($version->file_size)->toBeInt()
        ->and($version->required_variables)->toBe(['ESTUDANTE_NOME'])
        ->and($version->optional_variables)->toBe(['CURSO_NOME'])
        ->and($version->detected_variables)->toBe(['ESTUDANTE_NOME', 'CURSO_NOME'])
        ->and($version->validation_report)->toBe(['errors' => [], 'warnings' => []])
        ->and($version->validated_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($version->documentTemplate->is($template))->toBeTrue()
        ->and($version->uploadedByAffiliation->is($uploader))->toBeTrue()
        ->and($version->validatedByAffiliation->is($reviewer))->toBeTrue()
        ->and($template->templateVersions()->whereKey($version->id)->exists())->toBeTrue();
});

test('allows sequential versions per template but rejects duplicate version numbers', function () {
    $template = DocumentTemplate::factory()->create();
    TemplateVersion::factory()->for($template, 'documentTemplate')->create(['version' => 1]);
    TemplateVersion::factory()->for($template, 'documentTemplate')->create(['version' => 2]);
    TemplateVersion::factory()->create(['version' => 1]);

    expect(fn () => TemplateVersion::factory()->for($template, 'documentTemplate')->create(['version' => 1]))
        ->toThrow(ValidationException::class);

    expect(TemplateVersion::whereBelongsTo($template, 'documentTemplate')->orderBy('version')->pluck('version')->all())->toBe([1, 2]);
});

test('does not create a new version for an identical DOCX within the same template', function () {
    $template = DocumentTemplate::factory()->create();
    $first = TemplateVersion::factory()->for($template, 'documentTemplate')->create();

    expect(fn () => TemplateVersion::factory()->for($template, 'documentTemplate')->create([
        'version' => 2,
        'file_sha256' => $first->file_sha256,
    ]))->toThrow(ValidationException::class);

    expect($template->templateVersions()->count())->toBe(1);
});

test('requires version identity, size, variable schema and uploader', function (string $field, mixed $value) {
    expect(fn () => TemplateVersion::factory()->create([$field => $value]))
        ->toThrow(ValidationException::class);
})->with([
    ['version', 0],
    ['file_sha256', 'invalid'],
    ['file_size', 0],
    ['required_variables', null],
    ['optional_variables', null],
    ['uploaded_by_affiliation_id', null],
]);

test('selects the newest validated version for future document generation', function () {
    $template = DocumentTemplate::factory()->create();
    $latest = TemplateVersion::factory()->validated()->for($template, 'documentTemplate')->create(['version' => 1]);
    $pending = TemplateVersion::factory()->for($template, 'documentTemplate')->create(['version' => 2]);

    expect($template->latestValidatedVersion->is($latest))->toBeTrue();

    $pending->update(['validated_at' => now()]);

    expect($template->fresh()->latestValidatedVersion->is($pending))->toBeTrue();
});

test('allows physical deletion of a version without generated documents', function () {
    $version = TemplateVersion::factory()->create();

    expect($version->delete())->toBeTrue()
        ->and(TemplateVersion::find($version->id))->toBeNull();
});

test('restricts deletion of referenced templates and affiliations', function () {
    $version = TemplateVersion::factory()->validated()->create();

    foreach ([
        ['document_templates', $version->document_template_id],
        ['affiliations', $version->uploaded_by_affiliation_id],
        ['affiliations', $version->validated_by_affiliation_id],
    ] as [$table, $id]) {
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))
            ->toThrow(QueryException::class);
    }

    $this->assertModelExists($version);
});

test('stores one DOCX in the private collection without replacing it', function () {
    Storage::fake('local');
    $version = TemplateVersion::factory()->create();
    $path = tempnam(sys_get_temp_dir(), 'template-').'.docx';
    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::CREATE);
    $archive->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $archive->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
    $archive->close();

    try {
        $media = $version->addMedia($path)->preservingOriginal()->toMediaCollection('template_file');

        expect($media->disk)->toBe('local')
            ->and($version->fresh()->getMedia('template_file'))->toHaveCount(1);

        expect(fn () => $version->addMedia($path)->preservingOriginal()->toMediaCollection('template_file'))
            ->toThrow(FileUnacceptableForCollection::class);

        expect($version->fresh()->getMedia('template_file'))->toHaveCount(1);

        $version->delete();

        expect(DB::table('media')->where('id', $media->id)->exists())->toBeFalse();
    } finally {
        unlink($path);
    }
});

test('records metadata changes in the activity log', function () {
    $version = TemplateVersion::factory()->create();
    $version->update(['validation_report' => ['errors' => [], 'warnings' => ['Revisão pendente']]]);

    expect(Activity::forSubject($version)->count())->toBe(2);
});
