<?php

namespace App\Mail;

use App\Enums\EmailMessagePurpose;
use App\Models\EmailDeliveryAttempt;
use App\Models\User;
use Illuminate\Mail\Mailable;
use LogicException;

class DeliveryMail extends Mailable
{
    public function __construct(public EmailDeliveryAttempt $attempt) {}

    public function build(): static
    {
        return match ($this->attempt->purpose) {
            EmailMessagePurpose::Notification => $this->notificationContent(),
            EmailMessagePurpose::AccountCreated => $this->accountCreatedContent(),
            EmailMessagePurpose::NewAffiliation => $this->affiliationCreatedContent(),
            EmailMessagePurpose::AccountEmailChanged => $this->accountEmailChangedContent(),
            default => throw new LogicException('Finalidade de e-mail inválida.'),
        };
    }

    private function notificationContent(): static
    {
        $message = $this->attempt->emailMessage;
        $body = $message->content_text ?? strip_tags((string) $message->content_html);

        return $this->subject($message->subject)
            ->markdown('emails.notification', [
                'messageSubject' => $message->subject,
                'body' => $body,
                'dashboardUrl' => route('dashboard'),
            ]);
    }

    private function accountCreatedContent(): static
    {
        $user = User::query()->where('email', $this->attempt->recipient_email)->firstOrFail();
        $affiliation = $user->affiliations()->orderBy('id')->firstOrFail();

        return $this->subject('Sua conta no Sistema de Gestão de Estágios foi criada')
            ->markdown('emails.invitation', [
                'affiliationName' => $affiliation->type->label(),
                'requestUrl' => route('password.request', ['email' => $this->attempt->recipient_email]),
            ]);
    }

    private function affiliationCreatedContent(): static
    {
        return $this->subject('Novo vínculo criado no Sistema de Gestão de Estágios')
            ->markdown('emails.affiliation-created', [
                'loginUrl' => route('login'),
            ]);
    }

    private function accountEmailChangedContent(): static
    {
        $message = $this->attempt->emailMessage;

        return $this->subject($message->subject)
            ->html($message->content_html)
            ->text('emails.delivery-text', ['body' => $message->content_text]);
    }
}
