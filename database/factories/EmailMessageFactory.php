<?php

namespace Database\Factories;

use App\Enums\EmailMessagePurpose;
use App\Models\Affiliation;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmailMessage>
 */
class EmailMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_id' => null,
            'user_id' => User::factory(),
            'affiliation_id' => null,
            'purpose' => EmailMessagePurpose::PasswordReset,
            'recipient_email' => fn (array $attributes): string => User::findOrFail($attributes['user_id'])->email,
            'subject' => null,
            'content_text' => null,
            'content_html' => null,
            'template_key' => 'auth.password-reset',
            'template_version' => '1',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function newAffiliation(): static
    {
        return $this->state(fn (): array => [
            'purpose' => EmailMessagePurpose::NewAffiliation,
            'template_key' => 'account.new-affiliation',
        ]);
    }

    public function operational(Affiliation $affiliation, DatabaseNotification $notification): static
    {
        return $this->state(fn (): array => [
            'notification_id' => $notification->id,
            'user_id' => null,
            'affiliation_id' => $affiliation->id,
            'purpose' => EmailMessagePurpose::Notification,
            'recipient_email' => $affiliation->email,
            'subject' => 'Documento disponível',
            'content_text' => 'Um documento está disponível para análise.',
            'content_html' => '<p>Um documento está disponível para análise.</p>',
            'template_key' => 'internship.document-available',
        ]);
    }
}
