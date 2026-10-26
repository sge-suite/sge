<?php

namespace App\Jobs;

use App\Actions\RequestEmailDelivery;
use App\Models\Affiliation;
use App\Models\EmailMessage;
use App\Models\SupervisorEvaluation;
use App\Support\EmailDeliveryContext;
use App\Support\EmailRecipientIdentity;
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

    public bool $internshipCompleted = false;

    public function __construct(public int $evaluationId, public int $recipientId, public string $noticeId,
        public string $title, public string $message, bool $internshipCompleted = false)
    {
        $this->internshipCompleted = $internshipCompleted;
    }

    public function handle(RequestEmailDelivery $delivery, InternshipEmailDetails $details, EmailRecipientIdentity $identities): void
    {
        DB::transaction(function () use ($delivery, $details, $identities): void {
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
            $subject = $this->internshipCompleted ? 'Sua avaliação foi aceita e o estágio está concluído' : $this->title;
            $body = $this->message;
            if ($this->internshipCompleted) {
                $body = 'O Setor de Estágio aceitou sua avaliação. O cumprimento integral da carga horária foi confirmado e o estágio está concluído.';
                if (filled($evaluation->review_notes)) {
                    $body .= "\n\n".'Orientação do Setor de Estágio: '.$this->message;
                }
            }
            $data = [
                'messageSubject' => $subject, 'body' => $body,
                'internshipDetails' => $internshipDetails,
                'dashboardUrl' => route('supervisor-evaluations.show', $this->evaluationId),
                'recipientIdentity' => $identities->forAffiliation($recipient), 'actionLabel' => 'Consultar avaliação',
                'nextStep' => $this->internshipCompleted ? 'Sua avaliação está encerrada. Você pode consultá-la pelo SGE.'
                    : 'Acesse a avaliação pelo SGE com o vínculo identificado nesta mensagem.',
            ];
            $contentHtml = (string) app(Markdown::class)->render('emails.notification', $data);
            $contentText = (string) app(Markdown::class)->renderText('emails.notification', $data);
            $delivery->notification($notification, $subject, $contentText, $contentHtml, $this->noticeId,
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
