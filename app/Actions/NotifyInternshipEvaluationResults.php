<?php

namespace App\Actions;

use App\Enums\EvaluationStatus;
use App\Enums\InternshipStatus;
use App\Models\Affiliation;
use App\Models\EmailMessage;
use App\Models\Internship;
use App\Support\EmailDeliveryContext;
use App\Support\EmailRecipientIdentity;
use App\Support\InternshipEmailDetails;
use App\Support\InternshipGradeCalculator;
use App\Support\InternshipRecipients;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\DatabaseNotification;
use LogicException;
use Ramsey\Uuid\Uuid;

class NotifyInternshipEvaluationResults
{
    public function __construct(private RequestEmailDelivery $delivery, private Markdown $markdown,
        private InternshipEmailDetails $details, private InternshipRecipients $recipients,
        private InternshipGradeCalculator $calculator, private EmailDeliveryContext $emailContext,
        private EmailRecipientIdentity $identities) {}

    /** O chamador mantém o estágio bloqueado na transação da aprovação/conclusão. */
    public function supervisorApproved(Internship $internship, DatabaseNotification $notification): void
    {
        $weights = $this->calculator->weights($internship->internship_type_snapshot['rules'] ?? []);
        $this->send($internship, $notification, 'Avaliação do supervisor aceita — estágio concluído',
            'O Setor de Estágio aceitou a avaliação do supervisor. A carga horária foi integralmente cumprida e o estágio está concluído.',
            ['Nota do supervisor' => $this->grade((float) $internship->supervisor_grade, $weights['supervisor_evaluation_weight'])],
            'Esta nota corresponde à avaliação do supervisor. A nota final será comunicada separadamente, quando as três notas estiverem disponíveis e válidas. Em caso de dúvidas, entre em contato com a Coordenação do seu curso.');
    }

    /** O chamador mantém o estágio bloqueado na transação que recalcula as notas. */
    public function finalGradeAvailable(Internship $internship): void
    {
        $noticeId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'sge:internship:'.$internship->id.':final-grade')->toString();
        if (EmailMessage::query()->where('idempotency_key', $noticeId)->exists()
            || $internship->status === InternshipStatus::Cancelled
            || $internship->supervisor_grade === null || $internship->report_grade === null
            || $internship->presentation_grade === null || $internship->consolidated_grade === null) {
            return;
        }
        $evaluation = $internship->currentSupervisorEvaluation;
        if ($evaluation?->status !== EvaluationStatus::Approved || $evaluation->hours_requirement_met !== true
            || $evaluation->internship_id !== $internship->id) {
            return;
        }
        $calculated = $this->calculator->calculate($internship, (float) $internship->report_grade, (float) $internship->presentation_grade);
        if ($calculated['consolidated_grade'] === null
            || $calculated['supervisor_grade'] !== (float) $internship->supervisor_grade
            || $calculated['consolidated_grade'] !== (float) $internship->consolidated_grade) {
            return;
        }
        $recipient = $this->recipients->forInternship($internship, lock: true)['student']['affiliation'] ?? null;
        if ($recipient === null) {
            return;
        }
        $title = 'Avaliação final do estágio disponível';
        $body = 'As notas do supervisor, do relatório e da apresentação foram registradas. A nota final calculada pelo SGE está disponível.';
        $notification = $recipient->notifications()->firstOrCreate(['id' => $noticeId], [
            'type' => self::class,
            'data' => ['title' => $title, 'message' => $body, 'route' => 'dashboard', 'internship_id' => $internship->id, 'event' => 'final-grade'],
        ]);
        $weights = $this->calculator->weights($internship->internship_type_snapshot['rules'] ?? []);
        $this->send($internship, $notification, $title, $body, [
            'Nota do supervisor' => $this->grade((float) $internship->supervisor_grade, $weights['supervisor_evaluation_weight']),
            'Nota do relatório' => $this->grade((float) $internship->report_grade, $weights['report_weight']),
            'Nota da apresentação' => $this->grade((float) $internship->presentation_grade, $weights['presentation_weight']),
            'Nota final' => $this->grade((float) $internship->consolidated_grade, 10),
        ], 'Em caso de dúvidas sobre as notas, entre em contato com a Coordenação do seu curso.');
    }

    /** @param array<string, string> $grades */
    private function send(Internship $internship, DatabaseNotification $notification, string $title, string $body, array $grades, string $explanation): void
    {
        if (EmailMessage::query()->where('idempotency_key', $notification->id)->exists()) {
            return;
        }
        $recipient = $notification->notifiable;
        if (! $recipient instanceof Affiliation) {
            throw new LogicException('O aviso acadêmico exige um vínculo destinatário.');
        }
        $data = ['messageSubject' => $title, 'body' => $body, 'internshipDetails' => $this->details->forInternship($internship),
            'grades' => $grades, 'explanation' => $explanation, 'dashboardUrl' => route('dashboard'),
            'recipientIdentity' => $this->identities->forAffiliation($recipient)];
        $html = (string) $this->markdown->render('emails.internship-evaluation-result', $data);
        $text = (string) $this->markdown->renderText('emails.internship-evaluation-result', $data);
        $this->delivery->notification($notification, $title, $text, $html, $notification->id, $this->emailContext->forInternship($internship));
    }

    private function grade(float $value, int $weight): string
    {
        return number_format($value, 1, ',', '.').' / '.$weight;
    }
}
