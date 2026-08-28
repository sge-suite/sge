<?php

use App\Enums\InternshipStatus;
use Tests\TestCase;

uses(TestCase::class);

test('provides internship status values and labels', function () {
    expect(InternshipStatus::values())->toBe([
        'pending_formalization',
        'awaiting_signatures',
        'pending_correction',
        'released',
        'in_progress',
        'paused',
        'completed',
        'cancelled',
    ])
        ->and(InternshipStatus::options())->toBe([
            'pending_formalization' => 'Em formalização',
            'awaiting_signatures' => 'Aguardando assinaturas',
            'pending_correction' => 'Com pendência documental',
            'released' => 'Liberado',
            'in_progress' => 'Em andamento',
            'paused' => 'Pausado',
            'completed' => 'Concluído',
            'cancelled' => 'Cancelado',
        ])
        ->and(InternshipStatus::PendingFormalization->label())->toBe('Em formalização')
        ->and(InternshipStatus::AwaitingSignatures->label())->toBe('Aguardando assinaturas')
        ->and(InternshipStatus::PendingCorrection->label())->toBe('Com pendência documental')
        ->and(InternshipStatus::Released->label())->toBe('Liberado')
        ->and(InternshipStatus::InProgress->label())->toBe('Em andamento')
        ->and(InternshipStatus::Paused->label())->toBe('Pausado')
        ->and(InternshipStatus::Completed->label())->toBe('Concluído')
        ->and(InternshipStatus::Cancelled->label())->toBe('Cancelado');
});
