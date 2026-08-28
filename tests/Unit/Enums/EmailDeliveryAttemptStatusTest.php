<?php

use App\Enums\EmailDeliveryAttemptStatus;
use Tests\TestCase;

uses(TestCase::class);

test('defines email delivery attempt status cases, values, labels and options', function () {
    expect(EmailDeliveryAttemptStatus::cases())->toBe([
        EmailDeliveryAttemptStatus::Queued,
        EmailDeliveryAttemptStatus::Sent,
        EmailDeliveryAttemptStatus::Failed,
    ])
        ->and(EmailDeliveryAttemptStatus::values())->toBe([
            'queued',
            'sent',
            'failed',
        ])
        ->and(EmailDeliveryAttemptStatus::options())->toBe([
            'queued' => 'Na fila',
            'sent' => 'Enviada',
            'failed' => 'Falhou',
        ])
        ->and(EmailDeliveryAttemptStatus::Queued->label())->toBe('Na fila')
        ->and(EmailDeliveryAttemptStatus::Sent->label())->toBe('Enviada')
        ->and(EmailDeliveryAttemptStatus::Failed->label())->toBe('Falhou');
});
