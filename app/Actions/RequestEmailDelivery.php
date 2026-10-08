<?php

namespace App\Actions;

use App\Enums\EmailDeliveryAttemptStatus;
use App\Enums\EmailMessagePurpose;
use App\Jobs\SendEmailDelivery;
use App\Models\Affiliation;
use App\Models\EmailDeliveryAttempt;
use App\Models\EmailMessage;
use App\Models\User;
use App\Support\EmailDeliveryContext;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

class RequestEmailDelivery
{
    public function notification(DatabaseNotification $notification, string $subject, string $contentText, ?string $contentHtml, string $deliveryKey): EmailDeliveryAttempt
    {
        $this->validateKey($deliveryKey);
        $recipient = $notification->notifiable;

        if (! $recipient instanceof Affiliation && ! $recipient instanceof User) {
            throw ValidationException::withMessages(['notification' => 'A notificação deve pertencer a uma conta ou vínculo.']);
        }

        return DB::transaction(function () use ($notification, $recipient, $subject, $contentText, $contentHtml, $deliveryKey): EmailDeliveryAttempt {
            $message = EmailMessage::query()->firstOrCreate(
                ['idempotency_key' => $deliveryKey],
                [
                    'notification_id' => $notification->id,
                    'purpose' => EmailMessagePurpose::Notification,
                    'subject' => $subject,
                    'content_text' => $contentText,
                    'content_html' => $contentHtml,
                ],
            );

            if ($message->notification_id !== $notification->id || $message->subject !== $subject ||
                $message->content_text !== $contentText || $message->content_html !== $contentHtml) {
                throw ValidationException::withMessages(['delivery_key' => 'Esta chave já pertence a outra mensagem.']);
            }

            return $this->reserve($deliveryKey, EmailMessagePurpose::Notification, $recipient->email, $message);
        });
    }

    public function accountCreated(string $recipientEmail, string $deliveryKey, ?Affiliation $affiliation = null): EmailDeliveryAttempt
    {
        $this->validateKey($deliveryKey);
        $scopeContext = $affiliation === null ? null : app(EmailDeliveryContext::class)->forAffiliation($affiliation);
        $subject = 'Sua conta no Sistema de Gestão de Estágios foi criada';
        $data = [
            ...$this->signatureData(),
            'affiliationName' => $affiliation?->type->label() ?? 'Vínculo administrativo',
            'requestUrl' => route('password.request', ['email' => $recipientEmail]),
        ];
        $markdown = app(Markdown::class);
        $contentHtml = (string) $markdown->render('emails.invitation', $data);
        $contentText = (string) $markdown->renderText('emails.invitation', $data);

        return DB::transaction(function () use ($deliveryKey, $recipientEmail, $subject, $contentHtml, $contentText, $scopeContext): EmailDeliveryAttempt {
            $message = EmailMessage::query()->firstOrCreate(['idempotency_key' => $deliveryKey], [
                'purpose' => EmailMessagePurpose::AccountCreated,
                'subject' => $subject,
                'content_text' => $contentText,
                'content_html' => $contentHtml,
                'template_key' => 'administrative.account-created',
                'template_version' => '1',
            ]);

            if ($message->purpose !== EmailMessagePurpose::AccountCreated || $message->subject !== $subject ||
                $message->content_text !== $contentText || $message->content_html !== $contentHtml) {
                throw ValidationException::withMessages(['delivery_key' => 'Esta chave já pertence a outra mensagem.']);
            }

            return $this->reserve($deliveryKey, EmailMessagePurpose::AccountCreated, $recipientEmail, $message, scopeContext: $scopeContext);
        });
    }

    public function affiliationCreated(string $recipientEmail, Affiliation $affiliation, string $deliveryKey): EmailDeliveryAttempt
    {
        $this->validateKey($deliveryKey);
        $affiliation->loadMissing('campus');
        $scopeContext = app(EmailDeliveryContext::class)->forAffiliation($affiliation);

        $subject = 'Novo vínculo criado no Sistema de Gestão de Estágios';
        $data = [
            ...$this->signatureData(),
            'affiliationName' => $affiliation->type->label(),
            'campusName' => $affiliation->campus_id === null ? 'Escopo global' : $affiliation->campus->name,
            'registrationNumber' => $affiliation->registration_number,
            'loginUrl' => route('login'),
        ];
        $markdown = app(Markdown::class);
        $contentHtml = (string) $markdown->render('emails.affiliation-created', $data);
        $contentText = (string) $markdown->renderText('emails.affiliation-created', $data);

        return DB::transaction(function () use ($deliveryKey, $recipientEmail, $subject, $contentHtml, $contentText, $scopeContext): EmailDeliveryAttempt {
            $message = EmailMessage::query()->firstOrCreate(
                ['idempotency_key' => $deliveryKey],
                [
                    'purpose' => EmailMessagePurpose::NewAffiliation,
                    'subject' => $subject,
                    'content_text' => $contentText,
                    'content_html' => $contentHtml,
                    'template_key' => 'administrative.affiliation-created',
                    'template_version' => '1',
                ],
            );

            if ($message->purpose !== EmailMessagePurpose::NewAffiliation || $message->subject !== $subject ||
                $message->content_text !== $contentText || $message->content_html !== $contentHtml) {
                throw ValidationException::withMessages(['delivery_key' => 'Esta chave já pertence a outra mensagem.']);
            }

            return $this->reserve($deliveryKey, EmailMessagePurpose::NewAffiliation, $recipientEmail, $message, scopeContext: $scopeContext);
        });
    }

    public function accountEmailChanged(string $recipientEmail, string $previousEmail, string $newEmail, string $deliveryKey, ?User $account = null): EmailDeliveryAttempt
    {
        $this->validateKey($deliveryKey);

        Validator::make(compact('recipientEmail', 'previousEmail', 'newEmail'), [
            'recipientEmail' => ['required', 'email', Rule::in([$previousEmail, $newEmail])],
            'previousEmail' => ['required', 'email'],
            'newEmail' => ['required', 'email', 'different:previousEmail'],
        ])->validate();

        $scopeContext = $account === null ? null : app(EmailDeliveryContext::class)->forUser($account);
        $subject = 'E-mail da conta alterado';
        $data = [
            ...$this->signatureData(),
            'previousEmail' => $previousEmail,
            'newEmail' => $newEmail,
            'loginUrl' => route('login'),
        ];
        $markdown = app(Markdown::class);
        $contentHtml = (string) $markdown->render('emails.account-email-changed', $data);
        $contentText = (string) $markdown->renderText('emails.account-email-changed', $data);

        return DB::transaction(function () use ($deliveryKey, $recipientEmail, $subject, $contentHtml, $contentText, $scopeContext): EmailDeliveryAttempt {
            $message = EmailMessage::query()->firstOrCreate(
                ['idempotency_key' => $deliveryKey],
                [
                    'purpose' => EmailMessagePurpose::AccountEmailChanged,
                    'subject' => $subject,
                    'content_text' => $contentText,
                    'content_html' => $contentHtml,
                ],
            );

            if ($message->purpose !== EmailMessagePurpose::AccountEmailChanged || $message->subject !== $subject ||
                $message->content_text !== $contentText || $message->content_html !== $contentHtml) {
                throw ValidationException::withMessages(['delivery_key' => 'Esta chave já pertence a outra mensagem.']);
            }

            return $this->reserve($deliveryKey, EmailMessagePurpose::AccountEmailChanged, $recipientEmail, $message, scopeContext: $scopeContext);
        });
    }

    public function administrativeChange(string $recipientEmail, string $subject, string $body, string $deliveryKey, User|Affiliation|null $record = null): EmailDeliveryAttempt
    {
        $this->validateKey($deliveryKey);
        $markdown = app(Markdown::class);
        $context = app(EmailDeliveryContext::class);
        $scopeContext = match (true) {
            $record instanceof Affiliation => $context->forAffiliation($record),
            $record instanceof User => $context->forUser($record),
            default => null,
        };
        $data = [...$this->signatureData(), 'messageSubject' => $subject, 'body' => $body, 'dashboardUrl' => route('login')];
        $contentHtml = (string) $markdown->render('emails.notification', $data);
        $contentText = (string) $markdown->renderText('emails.notification', $data);

        return DB::transaction(function () use ($deliveryKey, $recipientEmail, $subject, $contentHtml, $contentText, $scopeContext): EmailDeliveryAttempt {
            $message = EmailMessage::query()->firstOrCreate(['idempotency_key' => $deliveryKey], [
                'purpose' => EmailMessagePurpose::AdministrativeChange,
                'subject' => $subject, 'content_text' => $contentText, 'content_html' => $contentHtml,
            ]);
            if ($message->purpose !== EmailMessagePurpose::AdministrativeChange || $message->subject !== $subject || $message->content_text !== $contentText || $message->content_html !== $contentHtml) {
                throw ValidationException::withMessages(['delivery_key' => 'Esta chave já pertence a outra mensagem.']);
            }

            return $this->reserve($deliveryKey, EmailMessagePurpose::AdministrativeChange, $recipientEmail, $message, scopeContext: $scopeContext);
        });
    }

    public function retry(EmailDeliveryAttempt $attempt): EmailDeliveryAttempt
    {
        return DB::transaction(function () use ($attempt): EmailDeliveryAttempt {
            $first = EmailDeliveryAttempt::query()->where('delivery_key', $attempt->delivery_key)
                ->orderBy('id')->lockForUpdate()->firstOrFail();
            $latest = EmailDeliveryAttempt::query()->where('delivery_key', $first->delivery_key)
                ->orderByDesc('attempt_number')->firstOrFail();

            if ($latest->status !== EmailDeliveryAttemptStatus::Failed) {
                return $latest;
            }

            if ($latest->attempt_number >= 3) {
                throw ValidationException::withMessages(['attempt_number' => 'O limite de tentativas foi atingido.']);
            }

            return $this->reserve($latest->delivery_key, $latest->purpose, $latest->recipient_email, $latest->emailMessage, $latest->attempt_number + 1, $latest->scope_context);
        });
    }

    /** @return array{requesterName: string|null, requesterRole: string|null} */
    private function signatureData(): array
    {
        $requester = app(CauserResolver::class)->resolve();

        if (! $requester instanceof Affiliation) {
            return ['requesterName' => null, 'requesterRole' => null];
        }

        $requester->loadMissing('user');

        return ['requesterName' => $requester->user->name, 'requesterRole' => $requester->type->label()];
    }

    /** @param array<string, mixed>|null $scopeContext */
    private function reserve(string $deliveryKey, EmailMessagePurpose $purpose, string $recipientEmail, ?EmailMessage $message = null, int $number = 1, ?array $scopeContext = null): EmailDeliveryAttempt
    {
        $attempt = EmailDeliveryAttempt::query()->firstOrCreate(
            ['delivery_key' => $deliveryKey, 'attempt_number' => $number],
            [
                'email_message_id' => $message?->id,
                'purpose' => $purpose,
                'recipient_email' => $recipientEmail,
                'status' => EmailDeliveryAttemptStatus::Queued,
                'queued_at' => now(),
                'scope_context' => $scopeContext,
            ],
        );

        if ($attempt->purpose !== $purpose || $attempt->recipient_email !== $recipientEmail ||
            $attempt->email_message_id !== $message?->id || $attempt->scope_context != $scopeContext) {
            throw ValidationException::withMessages(['delivery_key' => 'Esta chave já pertence a outro envio.']);
        }

        if ($attempt->wasRecentlyCreated) {
            SendEmailDelivery::dispatch($attempt->id)->afterCommit();
        }

        return $attempt;
    }

    private function validateKey(string $deliveryKey): void
    {
        Validator::make(['delivery_key' => $deliveryKey], ['delivery_key' => ['required', 'uuid']])->validate();
    }
}
