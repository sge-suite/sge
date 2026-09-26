<?php

namespace App\Actions;

use App\Enums\EmailDeliveryAttemptStatus;
use App\Enums\EmailMessagePurpose;
use App\Jobs\SendEmailDelivery;
use App\Models\Affiliation;
use App\Models\EmailDeliveryAttempt;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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

    public function invitation(string $recipientEmail, string $deliveryKey): EmailDeliveryAttempt
    {
        return $this->withoutContent(EmailMessagePurpose::NewAffiliation, $recipientEmail, $deliveryKey);
    }

    public function accountEmailChanged(string $recipientEmail, string $deliveryKey): EmailDeliveryAttempt
    {
        return $this->withoutContent(EmailMessagePurpose::AccountEmailChanged, $recipientEmail, $deliveryKey);
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

            return $this->reserve($latest->delivery_key, $latest->purpose, $latest->recipient_email, $latest->emailMessage, $latest->attempt_number + 1);
        });
    }

    private function withoutContent(EmailMessagePurpose $purpose, string $recipientEmail, string $deliveryKey): EmailDeliveryAttempt
    {
        $this->validateKey($deliveryKey);

        return DB::transaction(fn (): EmailDeliveryAttempt => $this->reserve($deliveryKey, $purpose, $recipientEmail));
    }

    private function reserve(string $deliveryKey, EmailMessagePurpose $purpose, string $recipientEmail, ?EmailMessage $message = null, int $number = 1): EmailDeliveryAttempt
    {
        $attempt = EmailDeliveryAttempt::query()->firstOrCreate(
            ['delivery_key' => $deliveryKey, 'attempt_number' => $number],
            [
                'email_message_id' => $message?->id,
                'purpose' => $purpose,
                'recipient_email' => $recipientEmail,
                'status' => EmailDeliveryAttemptStatus::Queued,
                'queued_at' => now(),
            ],
        );

        if ($attempt->purpose !== $purpose || $attempt->recipient_email !== $recipientEmail ||
            $attempt->email_message_id !== $message?->id) {
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
