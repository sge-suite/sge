<?php

namespace App\Actions;

use App\Enums\AffiliationType;
use App\Enums\InternshipStatus;
use App\Models\Internship;
use App\Support\EmailDeliveryContext;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Str;
use InvalidArgumentException;

class NotifyInternshipExecutionStatus
{
    public function __construct(private RequestEmailDelivery $delivery, private Markdown $markdown) {}

    /** O chamador mantém o campus e o estágio bloqueados na mesma transação. */
    public function handle(Internship $internship, InternshipStatus $previous, CarbonImmutable $referenceDate): void
    {
        if ($internship->status === $previous) {
            return;
        }
        [$event, $title, $explanation] = match (true) {
            $previous === InternshipStatus::Released && $internship->status === InternshipStatus::InProgress => [
                'started', 'Seu estágio foi iniciado', 'A data planejada de início foi alcançada e o estágio passou para em andamento.',
            ],
            $previous === InternshipStatus::Released && $internship->status === InternshipStatus::Paused => [
                'started_paused', 'Seu estágio foi iniciado em pausa', 'A data planejada de início foi alcançada, mas há uma pausa vigente. As horas do período de pausa não são computadas.',
            ],
            $previous === InternshipStatus::InProgress && $internship->status === InternshipStatus::Paused => [
                'paused', 'Seu estágio está pausado', 'Uma pausa está vigente. As horas do período de pausa não são computadas.',
            ],
            $previous === InternshipStatus::Paused && $internship->status === InternshipStatus::InProgress => [
                'resumed', 'Seu estágio foi retomado', 'Não há mais pausa vigente e o estágio voltou para em andamento.',
            ],
            default => throw new InvalidArgumentException('Esta transição não corresponde a início, pausa ou retomada de estágio.'),
        };
        $recipient = $internship->studentAffiliation()->where('type', AffiliationType::Student)->lockForUpdate()->firstOrFail();
        $request = $internship->internshipRequest()->where('affiliation_id', $recipient->id)->first();
        $url = $request === null ? route('dashboard') : route('internship-requests.show', $request);
        $subject = $title.' — estágio nº '.$internship->id;
        $text = implode("\n\n", ['Estágio nº '.$internship->id.'. '.$explanation,
            'Situação anterior: '.$previous->label().'. Situação atual: '.$internship->status->label().'.',
            'Data de referência: '.formatShort($referenceDate).'.',
            'Início planejado: '.formatShort($internship->planned_start_date).'. Previsão de término: '.formatShort($internship->projected_end_date).'.']);
        $noticeId = (string) Str::uuid();
        $notification = $recipient->notifications()->create([
            'id' => $noticeId,
            'type' => self::class,
            'data' => ['title' => $title, 'message' => $text, 'route' => $request === null ? 'dashboard' : 'internship-requests.show',
                'internship_id' => $internship->id, 'internship_request_id' => $request?->id, 'event' => $event,
                'previous_status' => $previous->value, 'status' => $internship->status->value, 'reference_date' => $referenceDate->toDateString()],
        ]);
        $contentHtml = (string) $this->markdown->render('emails.notification', [
            'messageSubject' => $subject, 'body' => $text, 'dashboardUrl' => $url,
        ]);
        $this->delivery->notification($notification, $subject, $text, $contentHtml, $noticeId, app(EmailDeliveryContext::class)->forInternship($internship));
    }
}
