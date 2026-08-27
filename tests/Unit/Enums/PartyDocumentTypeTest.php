<?php

use App\Enums\PartyDocumentType;
use Tests\TestCase;

uses(TestCase::class);

test('provides party document type values and labels', function () {
    expect(PartyDocumentType::values())->toBe([
        'cpf',
        'cnpj',
    ])
        ->and(PartyDocumentType::options())->toBe([
            'cpf' => 'CPF',
            'cnpj' => 'CNPJ',
        ])
        ->and(PartyDocumentType::Cpf->label())->toBe('CPF')
        ->and(PartyDocumentType::Cnpj->label())->toBe('CNPJ');
});
