<?php

namespace Database\Factories;

use App\Enums\EmailDeliveryAttemptStatus;
use App\Enums\EmailMessagePurpose;
use App\Models\EmailDeliveryAttempt;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmailDeliveryAttempt>
 */
class EmailDeliveryAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email_message_id' => EmailMessage::factory(),
            'delivery_key' => (string) Str::uuid(),
            'purpose' => EmailMessagePurpose::Notification,
            'recipient_email' => fake()->safeEmail(),
            'requested_by_affiliation_id' => null,
            'attempt_number' => 1,
            'status' => EmailDeliveryAttemptStatus::Queued,
            'provider' => 'smtp',
            'queued_at' => now(),
        ];
    }

    public function newAffiliation(User $recipient): static
    {
        return $this->state(fn (): array => [
            'email_message_id' => null,
            'purpose' => EmailMessagePurpose::NewAffiliation,
            'recipient_email' => $recipient->email,
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => EmailDeliveryAttemptStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => EmailDeliveryAttemptStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => 'smtp_rejected',
        ]);
    }
}
