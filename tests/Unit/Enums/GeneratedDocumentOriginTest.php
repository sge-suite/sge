<?php

use App\Enums\GeneratedDocumentOrigin;
use Tests\TestCase;

uses(TestCase::class);

test('provides generated document origin values and labels', function () {
    expect(GeneratedDocumentOrigin::values())->toBe([
        'sge',
        'granting_party',
    ])
        ->and(GeneratedDocumentOrigin::options())->toBe([
            'sge' => 'SGE',
            'granting_party' => 'Parte concedente',
        ])
        ->and(GeneratedDocumentOrigin::SGE->label())->toBe('SGE')
        ->and(GeneratedDocumentOrigin::GrantingParty->label())->toBe('Parte concedente');
});
