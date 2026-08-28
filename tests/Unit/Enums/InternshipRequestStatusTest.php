<?php

use App\Enums\InternshipRequestStatus;
use Tests\TestCase;

uses(TestCase::class);

test('defines internship request status cases, values, labels and options', function () {
    expect(InternshipRequestStatus::cases())->toBe([
        InternshipRequestStatus::Draft,
        InternshipRequestStatus::Submitted,
        InternshipRequestStatus::UnderReview,
        InternshipRequestStatus::PendingCorrection,
        InternshipRequestStatus::Accepted,
        InternshipRequestStatus::Rejected,
        InternshipRequestStatus::Withdrawn,
    ])
        ->and(InternshipRequestStatus::values())->toBe([
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
