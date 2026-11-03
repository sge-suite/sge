<?php

namespace App\Actions;

use App\Enums\AffiliationType;
use App\Enums\InternshipStatus;
use App\Models\Internship;
use App\Support\EmailDeliveryContext;
use App\Support\EmailRecipientIdentity;
use App\Support\InternshipEmailDetails;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Str;
use InvalidArgumentException;

class NotifyInternshipExecutionStatus
{
    public function __construct(private RequestEmailDelivery $delivery, private Markdown $markdown, private InternshipEmailDetails $details,
        private EmailRecipientIdentity $identities) {}

    /** O chamador mantém o campus e o estágio bloqueados na mesma transação. */
    public function handle(Internship $internship, InternshipStatus $previous, CarbonImmutable $referenceDate): void
    {
        if ($internship->status === $previous) {
            return;
        }
        [$title, $explanation] = match (true) {
            $previous === InternshipStatus::Released && $internship->status === InternshipStatus::InProgress => [
                'Seu estágio foi iniciado', 'A data planejada de início foi alcançada e o estágio passou para em andamento.',
            ],
            $previous === InternshipStatus::Released && $internship->status === InternshipStatus::Paused => [
                'Seu estágio foi iniciado em pausa', 'A data planejada de início foi alcançada, mas há uma pausa vigente. As horas do período de pausa não são computadas.',
            ],
            $previous === InternshipStatus::InProgress && $internship->status === InternshipStatus::Paused => [
                'Seu estágio está pausado', 'Uma pausa está vigente. As horas do período de pausa não são computadas.',
            ],
            $previous === InternshipStatus::Paused && $internship->status === InternshipStatus::InProgress => [
                'Seu estágio foi retomado', 'Não há mais pausa vigente e o estágio voltou para em andamento.',
            ],
            default => throw new InvalidArgumentException('Esta transição não corresponde a início, pausa ou retomada de estágio.'),
        };
        $recipient = $internship->studentAffiliation()->where('type', AffiliationType::Student)->lockForUpdate()->firstOrFail();
        $request = $internship->internshipRequest()->where('affiliation_id', $recipient->id)->first();
        $url = $request === null ? route('dashboard') : route('internship-requests.show', $request);
        $subject = $title.' — estágio nº '.$internship->id;
        $noticeId = (string) Str::uuid();
        $details = $this->details->forInternship($internship);
        $data = ['messageSubject' => $title, 'body' => $explanation, 'dashboardUrl' => $url,
            'internshipDetails' => [...$details, 'Situação anterior' => $previous->label(),
                'Situação atual' => $internship->status->label(), 'Data de referência' => formatShort($referenceDate)],
            'recipientIdentity' => $this->identities->forAffiliation($recipient), 'actionLabel' => 'Acompanhar estágio'];
        $contentHtml = (string) $this->markdown->render('emails.notification', $data);
        $emailText = (string) $this->markdown->renderText('emails.notification', $data);
        $this->delivery->operationalNotice($recipient, $subject, $emailText, $contentHtml, $noticeId, app(EmailDeliveryContext::class)->forInternship($internship));
    }
}
