<?php

namespace App\Actions;

use App\Models\Internship;
use App\Support\EmailDeliveryContext;
use App\Support\InternshipEmailDetails;
use App\Support\InternshipRecipients;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Str;

class NotifyInternshipLifecycle
{
    public function __construct(private InternshipRecipients $recipients, private RequestEmailDelivery $delivery, private Markdown $markdown,
        private InternshipEmailDetails $details, private NotifyInternshipEvaluationResults $results) {}

    /** @param list<string> $categories */
    public function handle(Internship $internship, string $title, string $explanation, array $categories = ['student', 'advisor', 'supervisor'], bool $supervisorEvaluationApproved = false): void
    {
        $details = $this->details->forInternship($internship);
        foreach ($this->recipients->forInternship($internship, lock: true) as $category => $recipient) {
            if (! in_array($category, $categories, true) || $recipient['affiliation'] === null) {
                continue;
            }
            $noticeId = (string) Str::uuid();
            $text = 'Olá, '.$recipient['name'].".\n\n".'Você recebe este aviso como '.$recipient['role'].' do estágio nº '.$internship->id.".\n\n".$explanation
                ."\n\n".'Situação atual: '.$internship->status->label().'.';
            $notification = $recipient['affiliation']->notifications()->create(['id' => $noticeId, 'type' => self::class,
                'data' => ['title' => $title, 'message' => $text, 'route' => 'dashboard', 'internship_id' => $internship->id]]);
            if ($category === 'student' && $supervisorEvaluationApproved) {
                $this->results->supervisorApproved($internship, $notification);

                continue;
            }
            $html = (string) $this->markdown->render('emails.notification', ['messageSubject' => $title, 'body' => $text,
                'internshipDetails' => $details, 'dashboardUrl' => route('dashboard')]);
            $this->delivery->notification($notification, $title.' — estágio nº '.$internship->id, $text."\n\n".$this->details->text($details), $html, $noticeId, app(EmailDeliveryContext::class)->forInternship($internship));
        }
    }
}
