<?php

namespace App\Mail;

use App\Enums\EmailMessagePurpose;
use App\Models\EmailDeliveryAttempt;
use Illuminate\Mail\Mailable;
use LogicException;

class DeliveryMail extends Mailable
{
    public function __construct(public EmailDeliveryAttempt $attempt) {}

    public function build(): static
    {
        [$subject, $text, $html] = match ($this->attempt->purpose) {
            EmailMessagePurpose::Notification => [
                $this->attempt->emailMessage->subject,
                $this->attempt->emailMessage->content_text,
                $this->attempt->emailMessage->content_html,
            ],
            EmailMessagePurpose::NewAffiliation => $this->invitationContent(),
            EmailMessagePurpose::AccountEmailChanged => [
                'E-mail da conta alterado',
                'O endereço de e-mail de acesso à sua conta no Sistema de Gestão de Estágios foi alterado. Se você não reconhece essa alteração, procure a administração do sistema.',
                null,
            ],
            default => throw new LogicException('Finalidade de e-mail inválida.'),
        };

        $text ??= strip_tags((string) $html);
        $html ??= '<p>'.nl2br(e($text)).'</p>';

        return $this->subject($subject)->html($html)->text('emails.delivery-text', ['body' => $text]);
    }

    /** @return array{string, string, null} */
    private function invitationContent(): array
    {
        $url = route('password.request', ['email' => $this->attempt->recipient_email]);

        return [
            'Seu acesso ao Sistema de Gestão de Estágios',
            "Um vínculo foi criado para você no Sistema de Gestão de Estágios. Para definir sua senha, acesse {$url} e solicite o link de redefinição.",
            null,
        ];
    }
}
