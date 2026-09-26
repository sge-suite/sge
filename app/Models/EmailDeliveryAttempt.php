<?php

namespace App\Models;

use App\Enums\EmailDeliveryAttemptStatus;
use App\Enums\EmailMessagePurpose;
use Database\Factories\EmailDeliveryAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * @property int $id
 * @property int|null $email_message_id
 * @property string $delivery_key
 * @property EmailMessagePurpose|null $purpose
 * @property string $recipient_email
 * @property int|null $requested_by_affiliation_id
 * @property int $attempt_number
 * @property EmailDeliveryAttemptStatus|null $status
 * @property Carbon|null $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property-read EmailMessage|null $emailMessage
 */
#[Fillable(['email_message_id', 'delivery_key', 'purpose', 'recipient_email', 'requested_by_affiliation_id', 'attempt_number', 'status', 'provider', 'provider_message_id', 'queued_at', 'sent_at', 'failed_at', 'failure_reason'])]
#[Hidden(['recipient_email', 'provider_message_id'])]
class EmailDeliveryAttempt extends Model
{
    /** @use HasFactory<EmailDeliveryAttemptFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'purpose' => EmailMessagePurpose::class,
            'attempt_number' => 'integer',
            'status' => EmailDeliveryAttemptStatus::class,
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EmailMessage, $this> */
    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function requestedByAffiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'requested_by_affiliation_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $attempt): void {
            $requester = app(CauserResolver::class)->resolve();

            if ($requester instanceof Affiliation) {
                $attempt->requested_by_affiliation_id = $requester->id;
            } elseif ($requester instanceof User) {
                throw ValidationException::withMessages(['requested_by_affiliation_id' => 'O envio humano exige um vínculo ativo.']);
            }
        });

        static::saving(function (self $attempt): void {
            Validator::make([
                'email_message_id' => $attempt->email_message_id,
                'delivery_key' => $attempt->delivery_key,
                'purpose' => $attempt->purpose instanceof EmailMessagePurpose ? $attempt->purpose->value : null,
                'recipient_email' => $attempt->recipient_email,
                'requested_by_affiliation_id' => $attempt->requested_by_affiliation_id,
                'attempt_number' => $attempt->attempt_number,
                'status' => $attempt->status instanceof EmailDeliveryAttemptStatus ? $attempt->status->value : null,
                'failure_reason' => $attempt->failure_reason,
            ], [
                'email_message_id' => ['nullable', 'integer', 'min:1'],
                'delivery_key' => ['required', 'uuid'],
                'purpose' => ['required', Rule::enum(EmailMessagePurpose::class)],
                'recipient_email' => ['required', 'email'],
                'requested_by_affiliation_id' => ['nullable', 'integer', 'min:1'],
                'attempt_number' => ['required', 'integer', 'min:1', 'max:65535'],
                'status' => ['required', Rule::enum(EmailDeliveryAttemptStatus::class)],
                'failure_reason' => ['nullable', 'regex:/^[a-z0-9_.-]+$/', 'max:120'],
            ])->validate();

            if (! $attempt->exists) {
                $requiresMessage = in_array($attempt->purpose, [
                    EmailMessagePurpose::Notification,
                    EmailMessagePurpose::AccountEmailChanged,
                ], true);

                if ($requiresMessage !== ($attempt->email_message_id !== null)) {
                    throw ValidationException::withMessages(['email_message_id' => 'Notificações e avisos de alteração de e-mail exigem conteúdo; avisos de conta e vínculo não armazenam conteúdo.']);
                }

                if ($attempt->email_message_id !== null) {
                    $message = EmailMessage::query()->with('notification.notifiable')->find($attempt->email_message_id);

                    if ($message === null || $message->purpose !== $attempt->purpose) {
                        throw ValidationException::withMessages(['email_message_id' => 'A mensagem deve corresponder à finalidade do envio.']);
                    }

                    $notifiable = $message->notification?->notifiable;

                    if ($notifiable !== null && (! ($notifiable instanceof Affiliation || $notifiable instanceof User) ||
                        $notifiable->email !== $attempt->recipient_email)) {
                        throw ValidationException::withMessages(['recipient_email' => 'O destinatário não corresponde à notificação.']);
                    }
                }

                if ($attempt->requested_by_affiliation_id !== null) {
                    $affiliation = Affiliation::query()->active()->find($attempt->requested_by_affiliation_id);

                    if ($affiliation === null) {
                        throw ValidationException::withMessages(['requested_by_affiliation_id' => 'O vínculo solicitante deve estar ativo.']);
                    }
                }
            }

            if ($attempt->exists && $attempt->getRawOriginal('status') !== EmailDeliveryAttemptStatus::Queued->value) {
                throw ValidationException::withMessages(['status' => 'Uma tentativa finalizada é imutável.']);
            }

            if ($attempt->exists && ($attempt->isDirty('email_message_id') || $attempt->isDirty('delivery_key') || $attempt->isDirty('purpose') ||
                $attempt->isDirty('recipient_email') || $attempt->isDirty('requested_by_affiliation_id') || $attempt->isDirty('attempt_number') ||
                $attempt->isDirty('queued_at'))) {
                throw ValidationException::withMessages(['attempt_number' => 'A identidade e a reserva da tentativa são imutáveis.']);
            }

            if ($attempt->status === EmailDeliveryAttemptStatus::Queued &&
                ($attempt->sent_at !== null || $attempt->failed_at !== null || $attempt->failure_reason !== null)) {
                throw ValidationException::withMessages(['status' => 'Uma tentativa na fila não pode ter resultado.']);
            }

            if ($attempt->status === EmailDeliveryAttemptStatus::Sent &&
                ($attempt->sent_at === null || $attempt->failed_at !== null || $attempt->failure_reason !== null)) {
                throw ValidationException::withMessages(['sent_at' => 'Uma tentativa enviada exige data de envio.']);
            }

            if ($attempt->status === EmailDeliveryAttemptStatus::Failed &&
                ($attempt->failed_at === null || $attempt->sent_at !== null || $attempt->failure_reason === null)) {
                throw ValidationException::withMessages(['failed_at' => 'Uma tentativa falha exige data e motivo sanitizado.']);
            }
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages(['email_delivery_attempt' => 'O histórico de entregas não pode ser excluído.']);
        });
    }
}
