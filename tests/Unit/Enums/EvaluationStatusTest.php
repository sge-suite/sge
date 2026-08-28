<?php

use App\Enums\EvaluationStatus;
use Tests\TestCase;

uses(TestCase::class);

test('defines evaluation status cases, values, labels and options', function () {
    expect(EvaluationStatus::cases())->toBe([
        EvaluationStatus::Draft,
        EvaluationStatus::Submitted,
        EvaluationStatus::Returned,
        EvaluationStatus::Approved,
        EvaluationStatus::Cancelled,
    ])
        ->and(EvaluationStatus::values())->toBe([
            'draft',
            'submitted',
            'returned',
            'approved',
            'cancelled',
        ])
        ->and(EvaluationStatus::options())->toBe([
            'draft' => 'Rascunho',
            'submitted' => 'Enviada',
            'returned' => 'Devolvida',
            'approved' => 'Aprovada',
            'cancelled' => 'Cancelada',
        ])
        ->and(EvaluationStatus::Draft->label())->toBe('Rascunho')
        ->and(EvaluationStatus::Submitted->label())->toBe('Enviada')
        ->and(EvaluationStatus::Returned->label())->toBe('Devolvida')
        ->and(EvaluationStatus::Approved->label())->toBe('Aprovada')
        ->and(EvaluationStatus::Cancelled->label())->toBe('Cancelada');
});
