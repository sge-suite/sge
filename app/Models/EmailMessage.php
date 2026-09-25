<?php

namespace App\Models;

use App\Enums\EmailMessagePurpose;
use Database\Factories\EmailMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Fillable(['notification_id', 'purpose', 'subject', 'content_text', 'content_html', 'template_key', 'template_version', 'idempotency_key'])]
#[Hidden(['subject', 'content_text', 'content_html'])]
class EmailMessage extends Model
{
    /** @use HasFactory<EmailMessageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'purpose' => EmailMessagePurpose::class,
        ];
    }

    /** @return BelongsTo<DatabaseNotification, $this> */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(DatabaseNotification::class);
    }

    /** @return HasMany<EmailDeliveryAttempt, $this> */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(EmailDeliveryAttempt::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            Validator::make([
                'purpose' => $message->purpose?->value,
                'subject' => $message->subject,
                'content_text' => $message->content_text,
                'content_html' => $message->content_html,
                'idempotency_key' => $message->idempotency_key,
            ], [
                'purpose' => ['required', Rule::enum(EmailMessagePurpose::class)],
                'subject' => ['required', 'string'],
                'content_text' => ['required_without:content_html', 'nullable', 'string'],
                'content_html' => ['required_without:content_text', 'nullable', 'string'],
                'idempotency_key' => ['required', 'uuid'],
            ])->validate();

            if ($message->purpose !== EmailMessagePurpose::Notification ||
                ($message->notification_id !== null && ! DatabaseNotification::find($message->notification_id))) {
                throw ValidationException::withMessages(['purpose' => 'Somente conteúdo de notificação pode ser armazenado.']);
            }
        });

        static::updating(function (): void {
            throw ValidationException::withMessages(['email_message' => 'O snapshot da mensagem é imutável.']);
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages(['email_message' => 'O snapshot da mensagem não pode ser excluído.']);
        });
    }
}
