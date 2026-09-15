<?php

use App\Enums\EmancipationEvidenceStatus;
use Tests\TestCase;

uses(TestCase::class);

test('defines emancipation evidence status cases, values, labels and options', function () {
    expect(EmancipationEvidenceStatus::cases())->toBe([
        EmancipationEvidenceStatus::Submitted,
        EmancipationEvidenceStatus::UnderReview,
        EmancipationEvidenceStatus::Approved,
        EmancipationEvidenceStatus::Returned,
        EmancipationEvidenceStatus::Cancelled,
    ])
        ->and(EmancipationEvidenceStatus::values())->toBe([
            'submitted',
            'under_review',
            'approved',
            'returned',
            'cancelled',
        ])
        ->and(EmancipationEvidenceStatus::options())->toBe([
            'submitted' => 'Enviada',
            'under_review' => 'Em análise',
            'approved' => 'Aprovada',
            'returned' => 'Devolvida',
            'cancelled' => 'Cancelada',
        ])
        ->and(EmancipationEvidenceStatus::Submitted->label())->toBe('Enviada')
        ->and(EmancipationEvidenceStatus::UnderReview->label())->toBe('Em análise')
        ->and(EmancipationEvidenceStatus::Approved->label())->toBe('Aprovada')
        ->and(EmancipationEvidenceStatus::Returned->label())->toBe('Devolvida')
        ->and(EmancipationEvidenceStatus::Cancelled->label())->toBe('Cancelada');
});
