<?php

namespace App\Jobs;

use App\Actions\RequestEmailDelivery;
use App\Models\Affiliation;
use App\Models\EmailMessage;
use App\Models\SupervisorEvaluation;
use App\Support\EmailDeliveryContext;
use App\Support\InternshipEmailDetails;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeliverSupervisorEvaluationNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public int $evaluationId, public int $recipientId, public string $noticeId,
        public string $title, public string $message) {}

    public function handle(RequestEmailDelivery $delivery, InternshipEmailDetails $details): void
    {
        DB::transaction(function () use ($delivery, $details): void {
            $recipient = Affiliation::query()->lockForUpdate()->findOrFail($this->recipientId);
            if (EmailMessage::query()->where('idempotency_key', $this->noticeId)->exists()) {
                return;
            }
            $notification = $recipient->notifications()->firstOrCreate(['id' => $this->noticeId], [
                'type' => self::class,
                'data' => ['title' => $this->title, 'message' => $this->message, 'route' => 'supervisor-evaluations.show', 'supervisor_evaluation_id' => $this->evaluationId],
            ]);
            $evaluation = SupervisorEvaluation::query()->with('internship.course')->findOrFail($this->evaluationId);
            $internshipDetails = $details->forInternship($evaluation->internship);
            $contentHtml = (string) app(Markdown::class)->render('emails.notification', [
                'messageSubject' => $this->title, 'body' => $this->message,
                'internshipDetails' => $internshipDetails,
                'dashboardUrl' => route('supervisor-evaluations.show', $this->evaluationId),
            ]);
            $delivery->notification($notification, $this->title, $this->message."\n\n".$details->text($internshipDetails), $contentHtml, $this->noticeId,
                app(EmailDeliveryContext::class)->forInternship($evaluation->internship));
        });
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Não foi possível preparar o aviso da avaliação do supervisor.', [
            'supervisor_evaluation_id' => $this->evaluationId, 'notice_id' => $this->noticeId,
        ]);
    }
}
