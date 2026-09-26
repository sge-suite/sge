<?php

use App\Enums\EmailMessagePurpose;
use Tests\TestCase;

uses(TestCase::class);

test('defines email message purpose cases, values, labels and options', function () {
    expect(EmailMessagePurpose::cases())->toBe([
        EmailMessagePurpose::Notification,
        EmailMessagePurpose::NewAffiliation,
        EmailMessagePurpose::AccountEmailChanged,
    ])
        ->and(EmailMessagePurpose::values())->toBe([
            'notification',
            'new_affiliation',
            'account_email_changed',
        ])
        ->and(EmailMessagePurpose::options())->toBe([
            'notification' => 'Notificação operacional',
            'new_affiliation' => 'Novo vínculo',
            'account_email_changed' => 'Alteração de e-mail da conta',
        ])
        ->and(EmailMessagePurpose::Notification->label())->toBe('Notificação operacional')
        ->and(EmailMessagePurpose::NewAffiliation->label())->toBe('Novo vínculo')
        ->and(EmailMessagePurpose::AccountEmailChanged->label())->toBe('Alteração de e-mail da conta');
});
