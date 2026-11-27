<?php

namespace App\Jobs;

use App\Enums\EmailDeliveryAttemptStatus;
use App\Mail\DeliveryMail;
use App\Models\EmailDeliveryAttempt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $attempt = DB::transaction(function (): ?EmailDeliveryAttempt {
            $locked = EmailDeliveryAttempt::query()->with('emailMessage')->lockForUpdate()->findOrFail($this->attemptId);

            return $locked->status === EmailDeliveryAttemptStatus::Queued ? $locked : null;
        });

        if ($attempt === null) {
            return;
        }

        try {
            $sent = Mail::to($attempt->recipient_email)->send(new DeliveryMail($attempt));

            DB::transaction(function () use ($sent): void {
                $locked = EmailDeliveryAttempt::query()->lockForUpdate()->findOrFail($this->attemptId);
                if ($locked->status !== EmailDeliveryAttemptStatus::Queued) {
                    return;
                }

                $locked->update([
                    'status' => EmailDeliveryAttemptStatus::Sent,
                    'provider' => config('mail.default'),
                    'provider_message_id' => $sent?->getMessageId(),
                    'sent_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            Log::error('Email delivery failed.', [
                'attempt_id' => $this->attemptId,
                'exception_class' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            DB::transaction(function (): void {
                $locked = EmailDeliveryAttempt::query()->lockForUpdate()->findOrFail($this->attemptId);
                if ($locked->status !== EmailDeliveryAttemptStatus::Queued) {
                    return;
                }

                $locked->update([
                    'status' => EmailDeliveryAttemptStatus::Failed,
                    'provider' => config('mail.default'),
                    'failed_at' => now(),
                    'failure_reason' => 'transport_failed',
                ]);
            });
        }
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
