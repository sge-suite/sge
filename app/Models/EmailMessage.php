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

#[Fillable(['notification_id', 'user_id', 'affiliation_id', 'purpose', 'recipient_email', 'subject', 'content_text', 'content_html', 'template_key', 'template_version', 'idempotency_key'])]
#[Hidden(['recipient_email', 'subject', 'content_text', 'content_html'])]
class EmailMessage extends Model
{
    /** @use HasFactory<EmailMessageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'purpose' => EmailMessagePurpose::class,
            'recipient_email' => 'encrypted',
            'subject' => 'encrypted',
            'content_text' => 'encrypted',
            'content_html' => 'encrypted',
        ];
    }

    /** @return BelongsTo<DatabaseNotification, $this> */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(DatabaseNotification::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function affiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class);
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
                'recipient_email' => $message->recipient_email,
                'idempotency_key' => $message->idempotency_key,
            ], [
                'purpose' => ['required', Rule::enum(EmailMessagePurpose::class)],
                'recipient_email' => ['required', 'email'],
                'idempotency_key' => ['required', 'uuid'],
            ])->validate();

            if ($message->purpose === EmailMessagePurpose::Notification) {
                $affiliation = Affiliation::find($message->affiliation_id);
                $notification = DatabaseNotification::find($message->notification_id);

                if ($message->user_id !== null || ! $affiliation || ! $notification ||
                    $notification->notifiable_type !== Affiliation::class ||
                    (int) $notification->notifiable_id !== $affiliation->id ||
                    $message->recipient_email !== $affiliation->email) {
                    throw ValidationException::withMessages(['affiliation_id' => 'A mensagem operacional exige notificação e endereço do vínculo destinatário.']);
                }
            } else {
                $user = User::find($message->user_id);
                $notification = $message->notification_id === null ? null : DatabaseNotification::find($message->notification_id);

                if ($message->affiliation_id !== null || ! $user || $message->recipient_email !== $user->email ||
                    $message->content_text !== null || $message->content_html !== null ||
                    ($message->notification_id !== null && (! $notification ||
                        $notification->notifiable_type !== User::class || (int) $notification->notifiable_id !== $user->id))) {
                    throw ValidationException::withMessages(['user_id' => 'A mensagem de conta exige usuário e não pode persistir conteúdo de acesso.']);
                }
            }
        });

        static::updating(function (): void {
            throw ValidationException::withMessages(['email_message' => 'O snapshot da mensagem é imutável.']);
        });
    }
}
