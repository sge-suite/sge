<?php

namespace Database\Factories;

use App\Enums\EmailDeliveryAttemptStatus;
use App\Models\EmailDeliveryAttempt;
use App\Models\EmailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'attempt_number' => 1,
            'status' => EmailDeliveryAttemptStatus::Queued,
            'provider' => 'smtp',
            'queued_at' => now(),
        ];
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
