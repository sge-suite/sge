<?php

use App\Enums\GeneratedDocumentType;
use App\Models\Campus;
use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

test('creates the document templates schema with a campus foreign key and no lookup key', function () {
    expect(DB::getDriverName())->toBe('pgsql');

    $columns = collect(Schema::getColumns('document_templates'))->keyBy('name');

    expect($columns->keys()->all())->toBe([
        'id', 'campus_id', 'name', 'description', 'document_type',
        'deactivated_at', 'created_at', 'updated_at',
    ]);

    foreach ([
        'id' => ['bigint', false],
        'campus_id' => ['bigint', true],
        'name' => ['character varying(255)', false],
        'description' => ['text', true],
        'document_type' => ['character varying(255)', false],
        'deactivated_at' => ['timestamp(0) without time zone', true],
        'created_at' => ['timestamp(0) without time zone', true],
        'updated_at' => ['timestamp(0) without time zone', true],
    ] as $name => [$type, $nullable]) {
        expect($columns->get($name))->toMatchArray(['type' => $type, 'nullable' => $nullable]);
    }

    $indexes = collect(Schema::getIndexes('document_templates'));
    $foreignKeys = collect(Schema::getForeignKeys('document_templates'));

    expect($indexes)->toHaveCount(2)
        ->and($indexes->firstWhere('primary', true))->toMatchArray([
            'columns' => ['id'], 'unique' => true,
        ])
        ->and($indexes->firstWhere('columns', ['campus_id']))->toMatchArray([
            'unique' => false,
        ])
        ->and($foreignKeys)->toHaveCount(1)
        ->and($foreignKeys->sole())->toMatchArray([
            'columns' => ['campus_id'], 'foreign_table' => 'campuses',
            'foreign_columns' => ['id'], 'on_delete' => 'restrict',
        ])
        ->and(Schema::hasColumn('document_templates', 'key'))->toBeFalse()
        ->and(Schema::hasColumn('document_templates', 'file_path'))->toBeFalse()
        ->and(Schema::hasColumn('document_templates', 'deleted_at'))->toBeFalse();
});

test('rolls back and reapplies the document templates migration', function () {
    $path = glob(database_path('migrations/*_create_document_templates_table.php'))[0];
    $batch = DB::table('migrations')
        ->where('migration', pathinfo($path, PATHINFO_FILENAME))
        ->value('batch');

    $this->artisan('migrate:rollback', [
        '--path' => [$path],
        '--realpath' => true,
        '--batch' => $batch,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(Schema::hasTable('document_templates'))->toBeFalse()
        ->and(Schema::hasTable('campuses'))->toBeTrue();

    $this->artisan('migrate', [
        '--path' => [$path],
        '--realpath' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(Schema::hasTable('document_templates'))->toBeTrue();
});

test('casts document type and relates campus templates', function (GeneratedDocumentType $type) {
    $campus = Campus::factory()->create();
    $template = DocumentTemplate::factory()->for($campus)->create([
        'document_type' => $type,
        'description' => '  Modelo institucional.  ',
    ])->fresh();

    expect($template->document_type)->toBe($type)
        ->and($template->description)->toBe('Modelo institucional.')
        ->and($template->campus->is($campus))->toBeTrue()
        ->and($campus->documentTemplates()->whereKey($template->id)->exists())->toBeTrue();
})->with(GeneratedDocumentType::cases());

test('supports global and campus templates selected by id', function () {
    $firstCampus = Campus::factory()->create();
    $secondCampus = Campus::factory()->create();
    $global = DocumentTemplate::factory()->create(['name' => 'Termo de compromisso']);
    $firstLocal = DocumentTemplate::factory()->for($firstCampus)->create(['name' => 'Termo de compromisso']);
    $secondLocal = DocumentTemplate::factory()->for($secondCampus)->create(['name' => 'Termo de compromisso']);

    expect(DocumentTemplate::active()->availableToCampus($firstCampus)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$global->id, $firstLocal->id])->sort()->values()->all())
        ->and(DocumentTemplate::active()->availableToCampus($secondCampus->id)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$global->id, $secondLocal->id])->sort()->values()->all());
});

test('allows multiple templates with the same name in one campus', function () {
    $campus = Campus::factory()->create();
    $first = DocumentTemplate::factory()->for($campus)->create(['name' => 'Termo de compromisso']);
    $second = DocumentTemplate::factory()->for($campus)->create(['name' => 'Termo de compromisso']);

    expect($first->id)->not->toBe($second->id)
        ->and($first->name)->toBe($second->name);
});

test('requires identification and document type', function (string $field) {
    expect(fn () => DocumentTemplate::factory()->create([$field => null]))
        ->toThrow(ValidationException::class);
})->with(['name', 'document_type']);

test('allows optional description and filters deactivated templates', function () {
    $active = DocumentTemplate::factory()->create(['description' => null]);
    $deactivated = DocumentTemplate::factory()->deactivated()->create();

    expect($active->fresh()->description)->toBeNull()
        ->and($deactivated->fresh()->deactivated_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and(DocumentTemplate::active()->pluck('id')->all())->toBe([$active->id]);

    $deactivated->update(['deactivated_at' => null]);

    expect(DocumentTemplate::active()->count())->toBe(2);
});

test('requires an active campus when assigning a scoped template', function () {
    $campus = Campus::factory()->create();
    $template = DocumentTemplate::factory()->for($campus)->create();
    $campus->update(['deactivated_at' => now()]);

    expect(fn () => DocumentTemplate::factory()->for($campus)->create())
        ->toThrow(ValidationException::class);

    $template->update(['name' => 'Modelo já existente']);

    expect($template->fresh()->name)->toBe('Modelo já existente');
});

test('restricts deletion of a campus referenced by a document template', function () {
    $campus = Campus::factory()->create();
    DocumentTemplate::factory()->for($campus)->create();

    expect(fn () => DB::transaction(fn () => DB::table('campuses')->where('id', $campus->id)->delete()))
        ->toThrow(QueryException::class);

    $this->assertModelExists($campus);
});

test('records template changes in the activity log', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $template = DocumentTemplate::factory()->create(['name' => 'Modelo original']);

    $template->update(['name' => 'Modelo atualizado']);

    expect(Activity::forSubject($template)->count())->toBe(2)
        ->and(Activity::forSubject($template)->where('event', 'updated')->sole()->causer_id)->toBe($user->id);
});
