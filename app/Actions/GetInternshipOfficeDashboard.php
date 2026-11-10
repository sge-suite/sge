<?php

namespace App\Actions;

use App\Enums\GeneratedDocumentStatus;
use App\Enums\InternshipRequestStatus;
use App\Enums\InternshipStatus;
use App\Models\Internship;
use App\Models\InternshipRequest;
use App\Models\User;
use App\Support\StudentInternshipContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

class GetInternshipOfficeDashboard
{
    public function __construct(private StudentInternshipContext $context) {}

    /** @return array<string, mixed> */
    public function __invoke(User $actor): array
    {
        Gate::forUser($actor)->authorize('viewReviewList', InternshipRequest::class);
        $owner = $this->context->selected($actor);
        $internships = Internship::query()->visibleToAffiliation($owner);
        $counts = (clone $internships)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $requests = InternshipRequest::query()->whereHas('affiliation', fn ($query) => $query->where('campus_id', $owner->campus_id))
            ->whereIn('status', [InternshipRequestStatus::Submitted, InternshipRequestStatus::UnderReview]);
        $today = CarbonImmutable::today(config('app.timezone'));
        $endingUntil = $today->addDays(7);
        $ending = (clone $internships)->whereIn('status', [InternshipStatus::Released, InternshipStatus::InProgress, InternshipStatus::Paused])
            ->whereDate('projected_end_date', '<=', $endingUntil->toDateString());
        $signatureDocuments = fn ($query) => $query->where('status', GeneratedDocumentStatus::AwaitingSignature);
        $signatures = (clone $internships)->whereHas('generatedDocuments', $signatureDocuments);
        $delayed = (clone $internships)->whereNull('released_at')
            ->whereIn('status', [InternshipStatus::PendingFormalization, InternshipStatus::AwaitingSignatures, InternshipStatus::PendingCorrection])
            ->whereDate('planned_start_date', '<', $today->toDateString());

        return [
            'campus' => $owner->campus->name,
            'readOnly' => $owner->campus->deactivated_at !== null,
            'pendingRequests' => (clone $requests)->count(),
            'formalization' => (int) $counts->get(InternshipStatus::PendingFormalization->value, 0),
            'inProgress' => (int) $counts->get(InternshipStatus::InProgress->value, 0),
            'paused' => (int) $counts->get(InternshipStatus::Paused->value, 0),
            'completed' => (int) $counts->get(InternshipStatus::Completed->value, 0),
            'requests' => $requests->with('course')->oldest('updated_at')->oldest('id')->limit(5)->get(),
            'endingCount' => (clone $ending)->count(),
            'endingInternships' => $ending->with(['course', 'advisorAffiliation.user'])->orderBy('projected_end_date')->orderBy('id')->limit(5)->get(),
            'endingUntil' => $endingUntil,
            'signatureCount' => (clone $signatures)->count(),
            'signatureInternships' => $signatures->withMin(['generatedDocuments' => $signatureDocuments], 'updated_at')
                ->withCasts(['generated_documents_min_updated_at' => 'datetime'])
                ->orderBy('generated_documents_min_updated_at')->oldest('id')->limit(5)->get(),
            'delayedCount' => (clone $delayed)->count(),
            'delayedInternships' => $delayed->oldest('planned_start_date')->oldest('id')->limit(5)->get(),
            'released' => (int) $counts->get(InternshipStatus::Released->value, 0),
        ];
    }
}
