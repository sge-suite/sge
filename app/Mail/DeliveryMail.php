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
        if ($this->attempt->attempt_number > 1) {
            return $this->retryContent();
        }

        return match ($this->attempt->purpose) {
            EmailMessagePurpose::Notification, EmailMessagePurpose::DocumentSignature, EmailMessagePurpose::InternshipRequestSubmitted => $this->notificationContent(),
            EmailMessagePurpose::AccountCreated => $this->accountCreatedContent(),
            EmailMessagePurpose::NewAffiliation => $this->affiliationCreatedContent(),
            EmailMessagePurpose::AccountEmailChanged, EmailMessagePurpose::AdministrativeChange => $this->accountEmailChangedContent(),
            default => throw new LogicException('Finalidade de e-mail inválida.'),
        };
    }

    private function retryContent(): static
    {
        $message = $this->attempt->emailMessage;

        if ($message === null || $message->purpose !== $this->attempt->purpose || blank($message->subject)
            || (blank($message->content_text) && blank($message->content_html))) {
            throw new LogicException('O conteúdo original não está disponível para repetir o transporte.');
        }

        $this->subject($message->subject);

        if (filled($message->content_html)) {
            $this->html($message->content_html);
        }

        if (filled($message->content_text)) {
            $this->text('emails.delivery-text', ['body' => $message->content_text]);
        }

        return $this;
    }

    private function notificationContent(): static
    {
        $message = $this->attempt->emailMessage;
        $body = $message->content_text ?? strip_tags((string) $message->content_html);

        if (filled($message->content_html)) {
            return $this->subject($message->subject)
                ->html($message->content_html)
                ->text('emails.delivery-text', ['body' => $body]);
        }

        return $this->subject($message->subject)
            ->markdown('emails.notification', [
                'messageSubject' => $message->subject,
                'body' => $body,
                'dashboardUrl' => route('dashboard'),
            ]);
    }

    private function accountCreatedContent(): static
    {
        $message = $this->attempt->emailMessage;

        if ($message !== null) {
            return $this->subject($message->subject)
                ->html($message->content_html)
                ->text('emails.delivery-text', ['body' => $message->content_text]);
        }

        $user = User::query()->where('email', $this->attempt->recipient_email)->firstOrFail();
        $affiliation = $user->affiliations()->with(['campus', 'course'])->orderBy('id')->firstOrFail();

        return $this->subject('Sua conta no Sistema de Gestão de Estágios foi criada')
            ->markdown('emails.invitation', [
                'affiliationName' => $affiliation->type->label(),
                'loginEmail' => $user->email,
                'campusName' => $affiliation->campus_id === null ? 'Escopo global' : $affiliation->campus->name,
                'registrationNumber' => $affiliation->registration_number,
                'courseName' => $affiliation->course?->name,
                'affiliationEmail' => $affiliation->email,
                'requestUrl' => route('password.request', ['email' => $this->attempt->recipient_email]),
            ]);
    }

    private function affiliationCreatedContent(): static
    {
        $message = $this->attempt->emailMessage;

        if ($message === null) {
            return $this->subject('Novo vínculo criado no Sistema de Gestão de Estágios')
                ->markdown('emails.affiliation-created', [
                    'affiliationName' => 'Vínculo administrativo',
                    'campusName' => 'Não informado',
                    'registrationNumber' => 'Não informado',
                    'loginUrl' => route('login'),
                ]);
        }

        return $this->subject($message->subject)
            ->html($message->content_html)
            ->text('emails.delivery-text', ['body' => $message->content_text]);
    }

    private function accountEmailChangedContent(): static
    {
        $message = $this->attempt->emailMessage;

        return $this->subject($message->subject)
            ->html($message->content_html)
            ->text('emails.delivery-text', ['body' => $message->content_text]);
    }
}
