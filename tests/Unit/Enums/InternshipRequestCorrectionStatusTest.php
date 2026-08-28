<?php

use App\Enums\InternshipRequestCorrectionStatus;
use Tests\TestCase;

uses(TestCase::class);

test('provides internship request correction status values and labels', function () {
    expect(InternshipRequestCorrectionStatus::values())->toBe([
        'open',
        'responded',
        'resolved',
        'cancelled',
    ])
        ->and(InternshipRequestCorrectionStatus::options())->toBe([
            'open' => 'Aberta',
            'responded' => 'Respondida',
            'resolved' => 'Resolvida',
            'cancelled' => 'Cancelada',
        ])
        ->and(InternshipRequestCorrectionStatus::Open->label())->toBe('Aberta')
        ->and(InternshipRequestCorrectionStatus::Responded->label())->toBe('Respondida')
        ->and(InternshipRequestCorrectionStatus::Resolved->label())->toBe('Resolvida')
        ->and(InternshipRequestCorrectionStatus::Cancelled->label())->toBe('Cancelada');
});
