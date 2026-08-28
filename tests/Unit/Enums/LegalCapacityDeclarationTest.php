<?php

use App\Enums\LegalCapacityDeclaration;
use Tests\TestCase;

uses(TestCase::class);

test('defines legal capacity declaration cases, values, labels and options', function () {
    expect(LegalCapacityDeclaration::cases())->toBe([
        LegalCapacityDeclaration::Adult,
        LegalCapacityDeclaration::Minor,
        LegalCapacityDeclaration::EmancipatedMinor,
    ])
        ->and(LegalCapacityDeclaration::values())->toBe([
            'adult',
            'minor',
            'emancipated_minor',
        ])
        ->and(LegalCapacityDeclaration::options())->toBe([
            'adult' => 'Maior de idade',
            'minor' => 'Menor de idade',
            'emancipated_minor' => 'Menor emancipado',
        ])
        ->and(LegalCapacityDeclaration::Adult->label())->toBe('Maior de idade')
        ->and(LegalCapacityDeclaration::Minor->label())->toBe('Menor de idade')
        ->and(LegalCapacityDeclaration::EmancipatedMinor->label())->toBe('Menor emancipado');
});
