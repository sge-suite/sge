<?php

use App\Enums\RegistrationRequestStatus;
use Tests\TestCase;

uses(TestCase::class);

test('defines registration request status cases, values, labels and options', function () {
    expect(RegistrationRequestStatus::cases())->toBe([
        RegistrationRequestStatus::Draft,
        RegistrationRequestStatus::Submitted,
        RegistrationRequestStatus::UnderReview,
        RegistrationRequestStatus::Approved,
        RegistrationRequestStatus::Rejected,
        RegistrationRequestStatus::Cancelled,
    ])
        ->and(RegistrationRequestStatus::values())->toBe([
            'draft',
            'submitted',
            'under_review',
            'approved',
            'rejected',
            'cancelled',
        ])
        ->and(RegistrationRequestStatus::options())->toBe([
            'draft' => 'Rascunho',
            'submitted' => 'Enviada',
            'under_review' => 'Em análise',
            'approved' => 'Aprovada',
            'rejected' => 'Recusada',
            'cancelled' => 'Cancelada',
        ])
        ->and(RegistrationRequestStatus::Draft->label())->toBe('Rascunho')
        ->and(RegistrationRequestStatus::Submitted->label())->toBe('Enviada')
        ->and(RegistrationRequestStatus::UnderReview->label())->toBe('Em análise')
        ->and(RegistrationRequestStatus::Approved->label())->toBe('Aprovada')
        ->and(RegistrationRequestStatus::Rejected->label())->toBe('Recusada')
        ->and(RegistrationRequestStatus::Cancelled->label())->toBe('Cancelada');
});
