<?php

use App\Enums\GeneratedDocumentStatus;
use Tests\TestCase;

uses(TestCase::class);

test('provides generated document status values and labels', function () {
    expect(GeneratedDocumentStatus::values())->toBe([
        'generated',
        'awaiting_signature',
        'signed',
        'cancelled',
    ])
        ->and(GeneratedDocumentStatus::options())->toBe([
            'generated' => 'Gerado',
            'awaiting_signature' => 'Aguardando assinatura',
            'signed' => 'Assinado',
            'cancelled' => 'Cancelado',
        ])
        ->and(GeneratedDocumentStatus::Generated->label())->toBe('Gerado')
        ->and(GeneratedDocumentStatus::AwaitingSignature->label())->toBe('Aguardando assinatura')
        ->and(GeneratedDocumentStatus::Signed->label())->toBe('Assinado')
        ->and(GeneratedDocumentStatus::Cancelled->label())->toBe('Cancelado');
});
