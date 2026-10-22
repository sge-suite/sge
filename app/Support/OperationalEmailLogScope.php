<?php

namespace App\Support;

use App\Actions\NotifyInternshipExecutionStatus;
use App\Actions\NotifyInternshipLifecycle;
use App\Actions\RequestEmailDelivery;
use App\Enums\AffiliationType;
use App\Enums\EmailMessagePurpose;
use App\Jobs\DeliverInternshipRequestNotice;
use App\Jobs\DeliverSupervisorEvaluationNotice;
use App\Models\EmailDeliveryAttempt;
use App\Models\GeneratedDocument;
use App\Models\Internship;
use App\Models\InternshipRequest;
use App\Models\SupervisorEvaluation;
use Illuminate\Database\Eloquent\Builder;

class OperationalEmailLogScope
{
    /** @return list<EmailMessagePurpose> */
    public function purposes(): array
    {
        return [EmailMessagePurpose::Notification, EmailMessagePurpose::DocumentSignature, EmailMessagePurpose::InternshipRequestSubmitted];
    }

    /**
     * @param  Builder<EmailDeliveryAttempt>  $query
     * @return Builder<EmailDeliveryAttempt>
     */
    public function apply(Builder $query, int $campusId): Builder
    {
        return $query->whereIn('purpose', $this->purposes())->where(function (Builder $query) use ($campusId): void {
            $query->whereJsonContains('scope_context->campus_ids', $campusId)
                ->orWhere(function (Builder $query) use ($campusId): void {
                    $query->whereNull('scope_context')->where(function (Builder $query) use ($campusId): void {
                        $this->legacyNotifications($query, $campusId);
                        $query->orWhere(function (Builder $query) use ($campusId): void {
                            $query->whereIn('purpose', [EmailMessagePurpose::DocumentSignature, EmailMessagePurpose::InternshipRequestSubmitted])
                                ->whereHas('requestedByAffiliation', fn (Builder $affiliations) => $affiliations->where('campus_id', $campusId)
                                    ->whereIn('type', [AffiliationType::InternshipOffice, AffiliationType::Student, AffiliationType::LEGACY_CAMPUS_ADMINISTRATOR]));
                        });
                    });
                });
        });
    }

    /** @param Builder<EmailDeliveryAttempt> $query */
    private function legacyNotifications(Builder $query, int $campusId): void
    {
        $internships = Internship::query()->whereHas('course', fn (Builder $courses) => $courses->where('campus_id', $campusId));
        $records = [
            [[NotifyInternshipLifecycle::class, NotifyInternshipExecutionStatus::class], 'internship_id', (clone $internships)->selectRaw('CAST(id AS TEXT)')],
            [[DeliverSupervisorEvaluationNotice::class], 'supervisor_evaluation_id', SupervisorEvaluation::query()->whereIn('internship_id', (clone $internships)->select('id'))->selectRaw('CAST(id AS TEXT)')],
            [[DeliverInternshipRequestNotice::class], 'internship_request_id', InternshipRequest::query()->whereHas('affiliation', fn (Builder $affiliations) => $affiliations->where('campus_id', $campusId))->selectRaw('CAST(id AS TEXT)')],
            [[RequestEmailDelivery::class], 'generated_document_id', GeneratedDocument::query()->whereIn('internship_id', (clone $internships)->select('id'))->selectRaw('CAST(id AS TEXT)')],
        ];
        $query->whereHas('emailMessage.notification', function (Builder $notifications) use ($records): void {
            $notifications->where(function (Builder $notifications) use ($records): void {
                foreach ($records as [$types, $field, $ids]) {
                    $notifications->orWhere(fn (Builder $notice) => $notice->whereIn('type', $types)->whereIn('data->'.$field, $ids));
                }
            });
        });
    }
}
