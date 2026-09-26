<?php

namespace App\Jobs;

use App\Enums\EmailDeliveryAttemptStatus;
use App\Mail\DeliveryMail;
use App\Models\EmailDeliveryAttempt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendEmailDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $attemptId) {}

    public function handle(): void
    {
        DB::transaction(function (): void {
            $attempt = EmailDeliveryAttempt::query()->with('emailMessage')->lockForUpdate()->findOrFail($this->attemptId);

            if ($attempt->status !== EmailDeliveryAttemptStatus::Queued) {
                return;
            }

            try {
                $sent = Mail::to($attempt->recipient_email)->send(new DeliveryMail($attempt));

                $attempt->update([
                    'status' => EmailDeliveryAttemptStatus::Sent,
                    'provider' => config('mail.default'),
                    'provider_message_id' => $sent?->getMessageId(),
                    'sent_at' => now(),
                ]);
            } catch (Throwable) {
                $attempt->update([
                    'status' => EmailDeliveryAttemptStatus::Failed,
                    'provider' => config('mail.default'),
                    'failed_at' => now(),
                    'failure_reason' => 'transport_failed',
                ]);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        $attempt = EmailDeliveryAttempt::query()->find($this->attemptId);

        if ($attempt?->status === EmailDeliveryAttemptStatus::Queued) {
            $attempt->update([
                'status' => EmailDeliveryAttemptStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => 'worker_failed',
            ]);
        }
    }
}
