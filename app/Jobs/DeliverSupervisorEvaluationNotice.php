<?php

namespace App\Jobs;

use App\Actions\RequestEmailDelivery;
use App\Models\Affiliation;
use App\Models\SupervisorEvaluation;
use App\Support\EmailDeliveryContext;
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

    public function handle(RequestEmailDelivery $delivery): void
    {
        DB::transaction(function () use ($delivery): void {
            $recipient = Affiliation::query()->lockForUpdate()->findOrFail($this->recipientId);
            $notification = $recipient->notifications()->firstOrCreate(['id' => $this->noticeId], [
                'type' => self::class,
                'data' => ['title' => $this->title, 'message' => $this->message, 'route' => 'supervisor-evaluations.show', 'supervisor_evaluation_id' => $this->evaluationId],
            ]);
            $contentHtml = (string) app(Markdown::class)->render('emails.notification', [
                'messageSubject' => $this->title, 'body' => $this->message,
                'dashboardUrl' => route('supervisor-evaluations.show', $this->evaluationId),
            ]);
            $evaluation = SupervisorEvaluation::query()->with('internship.course')->findOrFail($this->evaluationId);
            $delivery->notification($notification, $this->title, $this->message, $contentHtml, $this->noticeId,
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
