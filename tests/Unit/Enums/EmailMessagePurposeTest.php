<?php

use App\Enums\EmailMessagePurpose;
use Tests\TestCase;

uses(TestCase::class);

test('defines email message purpose cases, values, labels and options', function () {
    expect(EmailMessagePurpose::cases())->toBe([
        EmailMessagePurpose::Notification,
        EmailMessagePurpose::NewAffiliation,
    ])
        ->and(EmailMessagePurpose::values())->toBe([
            'notification',
            'new_affiliation',
        ])
        ->and(EmailMessagePurpose::options())->toBe([
            'notification' => 'Notificação operacional',
            'new_affiliation' => 'Novo vínculo',
        ])
        ->and(EmailMessagePurpose::Notification->label())->toBe('Notificação operacional')
        ->and(EmailMessagePurpose::NewAffiliation->label())->toBe('Novo vínculo');
});
