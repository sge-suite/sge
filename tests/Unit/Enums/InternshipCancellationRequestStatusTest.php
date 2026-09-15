<?php

use App\Enums\InternshipCancellationRequestStatus;
use Tests\TestCase;

uses(TestCase::class);

test('defines internship cancellation request status cases, values, labels and options', function () {
    expect(InternshipCancellationRequestStatus::cases())->toBe([
        InternshipCancellationRequestStatus::Submitted,
        InternshipCancellationRequestStatus::UnderReview,
        InternshipCancellationRequestStatus::Approved,
        InternshipCancellationRequestStatus::Rejected,
        InternshipCancellationRequestStatus::Withdrawn,
    ])
        ->and(InternshipCancellationRequestStatus::values())->toBe([
            'submitted',
            'under_review',
            'approved',
            'rejected',
            'withdrawn',
        ])
        ->and(InternshipCancellationRequestStatus::options())->toBe([
            'submitted' => 'Enviada',
            'under_review' => 'Em análise',
            'approved' => 'Aprovada',
            'rejected' => 'Recusada',
            'withdrawn' => 'Retirada pelo discente',
        ])
        ->and(InternshipCancellationRequestStatus::Submitted->label())->toBe('Enviada')
        ->and(InternshipCancellationRequestStatus::UnderReview->label())->toBe('Em análise')
        ->and(InternshipCancellationRequestStatus::Approved->label())->toBe('Aprovada')
        ->and(InternshipCancellationRequestStatus::Rejected->label())->toBe('Recusada')
        ->and(InternshipCancellationRequestStatus::Withdrawn->label())->toBe('Retirada pelo discente');
});
