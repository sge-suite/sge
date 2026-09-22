<?php

namespace App\Models;

use App\Enums\EmailDeliveryAttemptStatus;
use Database\Factories\EmailDeliveryAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Fillable(['email_message_id', 'attempt_number', 'status', 'provider', 'provider_message_id', 'queued_at', 'sent_at', 'failed_at', 'failure_reason'])]
#[Hidden(['provider_message_id'])]
class EmailDeliveryAttempt extends Model
{
    /** @use HasFactory<EmailDeliveryAttemptFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'status' => EmailDeliveryAttemptStatus::class,
            'provider_message_id' => 'encrypted',
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

    protected static function booted(): void
    {
        static::saving(function (self $attempt): void {
            Validator::make([
                'email_message_id' => $attempt->email_message_id,
                'attempt_number' => $attempt->attempt_number,
                'status' => $attempt->status?->value,
                'failure_reason' => $attempt->failure_reason,
            ], [
                'email_message_id' => ['required', 'integer', 'min:1'],
                'attempt_number' => ['required', 'integer', 'min:1', 'max:65535'],
                'status' => ['required', Rule::enum(EmailDeliveryAttemptStatus::class)],
                'failure_reason' => ['nullable', 'regex:/^[a-z0-9_.-]+$/', 'max:120'],
            ])->validate();

            if ($attempt->exists && $attempt->getRawOriginal('status') !== EmailDeliveryAttemptStatus::Queued->value) {
                throw ValidationException::withMessages(['status' => 'Uma tentativa finalizada é imutável.']);
            }

            if ($attempt->exists && ($attempt->isDirty('email_message_id') || $attempt->isDirty('attempt_number') || $attempt->isDirty('queued_at'))) {
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
    }
}
