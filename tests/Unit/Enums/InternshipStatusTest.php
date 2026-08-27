<?php

use App\Enums\InternshipStatus;
use Tests\TestCase;

uses(TestCase::class);

test('provides internship status values and labels', function () {
    expect(InternshipStatus::values())->toBe([
        'draft',
        'submitted',
        'under_review',
        'pending_correction',
        'rejected',
        'accepted',
        'released',
        'in_progress',
        'paused',
        'completed',
        'cancelled',
    ])
        ->and(InternshipStatus::options())->toBe([
            'draft' => 'Rascunho',
            'submitted' => 'Enviado',
            'under_review' => 'Em análise',
            'pending_correction' => 'Com pendência',
            'rejected' => 'Recusado',
            'accepted' => 'Aceito',
            'released' => 'Liberado',
            'in_progress' => 'Em andamento',
            'paused' => 'Pausado',
            'completed' => 'Concluído',
            'cancelled' => 'Cancelado',
        ])
        ->and(InternshipStatus::UnderReview->label())->toBe('Em análise');
});
