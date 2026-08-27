<?php

use App\Enums\GeneratedDocumentType;
use Tests\TestCase;

uses(TestCase::class);

test('defines all generated document types and persisted values', function () {
    expect(GeneratedDocumentType::cases())->toBe([
        GeneratedDocumentType::Main,
        GeneratedDocumentType::Addendum,
        GeneratedDocumentType::OrientationCertificate,
    ])
        ->and(GeneratedDocumentType::Main->value)->toBe('main')
        ->and(GeneratedDocumentType::Addendum->value)->toBe('addendum')
        ->and(GeneratedDocumentType::OrientationCertificate->value)->toBe('orientation_certificate');
});

test('provides generated document type values', function () {
    expect(GeneratedDocumentType::values())->toBe([
        'main',
        'addendum',
        'orientation_certificate',
    ]);
});

test('provides generated document type options', function () {
    expect(GeneratedDocumentType::options())->toBe([
        'main' => 'Documento principal',
        'addendum' => 'Aditivo',
        'orientation_certificate' => 'Atestado de orientação',
    ]);
});

test('provides generated document type labels in Portuguese', function () {
    expect(GeneratedDocumentType::Main->label())->toBe('Documento principal')
        ->and(GeneratedDocumentType::Addendum->label())->toBe('Aditivo')
        ->and(GeneratedDocumentType::OrientationCertificate->label())->toBe('Atestado de orientação');
});

test('converts persisted values and rejects invalid values', function () {
    expect(GeneratedDocumentType::from('main'))->toBe(GeneratedDocumentType::Main)
        ->and(GeneratedDocumentType::from('addendum'))->toBe(GeneratedDocumentType::Addendum)
        ->and(GeneratedDocumentType::from('orientation_certificate'))->toBe(GeneratedDocumentType::OrientationCertificate)
        ->and(fn () => GeneratedDocumentType::from('invalid'))->toThrow(ValueError::class);
});
