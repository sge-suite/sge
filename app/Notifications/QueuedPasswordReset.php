<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\EmailRecipientIdentity;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use LogicException;

class QueuedPasswordReset extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /** @var array{name: string, role: string, context: list<string>}|null */
    public ?array $recipientIdentity = null;

    /** @param array{name: string, role: string, context: list<string>}|null $recipientIdentity */
    public function __construct(#[\SensitiveParameter] string $token, ?array $recipientIdentity = null)
    {
        parent::__construct($token);
        $this->recipientIdentity = $recipientIdentity;
    }

    public function toMail($notifiable): MailMessage
    {
        if (! $notifiable instanceof User) {
            throw new LogicException('A recuperação de senha exige uma conta de usuário.');
        }

        return (new MailMessage)->subject('Defina uma nova senha para sua conta no SGE')
            ->markdown('emails.password-reset', [
                'recipientIdentity' => $this->recipientIdentity ?? app(EmailRecipientIdentity::class)->forAccount($notifiable),
                'resetUrl' => $this->resetUrl($notifiable),
                'expiresInMinutes' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
            ]);
    }
}
