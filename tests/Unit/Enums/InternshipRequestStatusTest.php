<?php

use App\Enums\InternshipRequestStatus;
use Tests\TestCase;

uses(TestCase::class);

test('provides internship request status values and labels', function () {
    expect(InternshipRequestStatus::values())->toBe([
        'draft',
        'submitted',
        'under_review',
        'pending_correction',
        'accepted',
        'rejected',
        'withdrawn',
    ])
        ->and(InternshipRequestStatus::options())->toBe([
            'draft' => 'Rascunho',
            'submitted' => 'Enviada',
            'under_review' => 'Em análise',
            'pending_correction' => 'Com pendência',
            'accepted' => 'Aceita',
            'rejected' => 'Recusada',
            'withdrawn' => 'Desistida',
        ])
        ->and(InternshipRequestStatus::Draft->label())->toBe('Rascunho')
        ->and(InternshipRequestStatus::Submitted->label())->toBe('Enviada')
        ->and(InternshipRequestStatus::UnderReview->label())->toBe('Em análise')
        ->and(InternshipRequestStatus::PendingCorrection->label())->toBe('Com pendência')
        ->and(InternshipRequestStatus::Accepted->label())->toBe('Aceita')
        ->and(InternshipRequestStatus::Rejected->label())->toBe('Recusada')
        ->and(InternshipRequestStatus::Withdrawn->label())->toBe('Desistida');
});
