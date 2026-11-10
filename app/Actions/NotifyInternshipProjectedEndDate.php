<?php

namespace App\Actions;

use App\Enums\AffiliationType;
use App\Enums\InternshipStatus;
use App\Models\Campus;
use App\Models\EmailMessage;
use App\Models\Internship;
use App\Support\EmailDeliveryContext;
use App\Support\EmailRecipientIdentity;
use App\Support\InternshipEmailDetails;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class NotifyInternshipProjectedEndDate
{
    public function __construct(
        private RequestEmailDelivery $delivery,
        private Markdown $markdown,
        private InternshipEmailDetails $details,
        private EmailRecipientIdentity $identities,
        private EmailDeliveryContext $emailContext,
    ) {}

    /** @return Builder<Internship> */
    public function eligibleInternships(CarbonImmutable $date): Builder
    {
        return Internship::query()
            ->whereIn('status', [InternshipStatus::Released, InternshipStatus::InProgress, InternshipStatus::Paused])
            ->whereBetween('projected_end_date', [$date->addDay()->toDateString(), $date->addDays(7)->toDateString()]);
    }

    public function handle(Internship $target, CarbonImmutable $date): bool
    {
        return DB::transaction(function () use ($target, $date): bool {
            $campus = Campus::query()->lockForUpdate()->find($target->course->campus_id);
            if ($campus === null || $campus->deactivated_at !== null) {
                return false;
            }

            $internship = $this->eligibleInternships($date)
                ->whereHas('course', fn (Builder $query) => $query->where('campus_id', $campus->id))
                ->lockForUpdate()->find($target->id);
            if ($internship === null) {
                return false;
            }

            $projectedEndDate = $internship->projected_end_date->toDateString();
            $noticeId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'sge:internship:'.$internship->id.':projected-end:'.$projectedEndDate)->toString();
            if (EmailMessage::query()->where('idempotency_key', $noticeId)->exists()) {
                return false;
            }

            $recipient = $internship->studentAffiliation()->active()
                ->where('type', AffiliationType::Student)->where('campus_id', $campus->id)
                ->lockForUpdate()->first();
            if ($recipient === null || ! filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
                return false;
            }

            $title = 'Previsão de término do seu estágio';
            $subject = $title.' — estágio nº '.$internship->id;
            $data = [
                'messageSubject' => $title,
                'body' => 'A data prevista de encerramento do seu estágio está próxima. Confira abaixo a previsão registrada no SGE.',
                'dashboardUrl' => route('internships.show', $internship),
                'internshipDetails' => $this->details->forInternship($internship),
                'recipientIdentity' => $this->identities->forAffiliation($recipient),
                'actionLabel' => 'Acompanhar estágio',
            ];
            $html = (string) $this->markdown->render('emails.notification', $data);
            $text = (string) $this->markdown->renderText('emails.notification', $data);
            $this->delivery->operationalNotice($recipient, $subject, $text, $html, $noticeId, $this->emailContext->forInternship($internship));

            return true;
        }, attempts: 3);
    }
}
