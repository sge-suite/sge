<?php

namespace App\Actions;

use App\Models\Internship;
use App\Support\EmailDeliveryContext;
use App\Support\EmailRecipientIdentity;
use App\Support\InternshipEmailDetails;
use App\Support\InternshipRecipients;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Str;

class NotifyInternshipLifecycle
{
    public function __construct(private InternshipRecipients $recipients, private RequestEmailDelivery $delivery, private Markdown $markdown,
        private InternshipEmailDetails $details, private NotifyInternshipEvaluationResults $results,
        private EmailRecipientIdentity $identities) {}

    /** @param list<string> $categories */
    public function handle(Internship $internship, string $title, string $explanation, array $categories = ['student', 'advisor', 'supervisor'], bool $supervisorEvaluationApproved = false): void
    {
        $details = $this->details->forInternship($internship);
        foreach ($this->recipients->forInternship($internship, lock: true) as $category => $recipient) {
            if (! in_array($category, $categories, true) || $recipient['affiliation'] === null) {
                continue;
            }
            if ($category === 'supervisor' && $supervisorEvaluationApproved) {
                continue;
            }
            $noticeId = (string) Str::uuid();
            if ($category === 'student' && $supervisorEvaluationApproved) {
                $this->results->supervisorApproved($internship, $recipient['affiliation'], $noticeId);

                continue;
            }
            $data = ['messageSubject' => $title, 'body' => $explanation,
                'internshipDetails' => [...$details, 'Situação do estágio' => $internship->status->label()], 'dashboardUrl' => route('dashboard'),
                'recipientIdentity' => $this->identities->forAffiliation($recipient['affiliation']),
                'nextStep' => 'Acompanhe os detalhes no SGE com o vínculo identificado nesta mensagem.'];
            $html = (string) $this->markdown->render('emails.notification', $data);
            $emailText = (string) $this->markdown->renderText('emails.notification', $data);
            $this->delivery->operationalNotice($recipient['affiliation'], $title.' — estágio nº '.$internship->id, $emailText, $html, $noticeId, app(EmailDeliveryContext::class)->forInternship($internship));
        }
    }
}
