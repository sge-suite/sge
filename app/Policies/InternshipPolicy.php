<?php

namespace App\Policies;

use App\Enums\AffiliationType;
use App\Enums\GeneratedDocumentType;
use App\Enums\InternshipStatus;
use App\Models\Internship;
use App\Models\User;
use App\Support\StudentInternshipContext;

class InternshipPolicy
{
    public function __construct(private StudentInternshipContext $context) {}

    public function viewOwnAny(User $user): bool
    {
        return $this->context->student($user) !== null;
    }

    public function viewAny(User $user): bool
    {
        $affiliation = $this->context->selected($user);

        return $affiliation?->campus !== null && in_array($affiliation->type, [AffiliationType::InternshipOffice,
            AffiliationType::Coordinator, AffiliationType::Advisor, AffiliationType::TeachingDirection], true);
    }

    public function view(User $user, Internship $internship): bool
    {
        $affiliation = $this->context->selected($user);
        if ($affiliation?->type === AffiliationType::Student) {
            return $this->viewOwnAny($user) && $internship->student_affiliation_id === $affiliation->id
                && $internship->course()->where('campus_id', $affiliation->campus_id)->exists();
        }

        return $this->viewAny($user) && Internship::query()->visibleToAffiliation($affiliation)->whereKey($internship->id)->exists();
    }

    public function update(User $user, Internship $internship): bool
    {
        return $this->view($user, $internship)
            && $this->context->selected($user)->type === AffiliationType::InternshipOffice
            && $this->context->selected($user)->campus->deactivated_at === null
            && ! in_array($internship->status, [InternshipStatus::Completed, InternshipStatus::Cancelled], true);
    }

    public function viewDocuments(User $user, Internship $internship): bool
    {
        if (! $this->view($user, $internship)) {
            return false;
        }

        $affiliation = $this->context->selected($user);

        return $affiliation->type === AffiliationType::InternshipOffice
            || ($affiliation->type === AffiliationType::Coordinator && $internship->course()->where(fn ($query) => $query
                ->where('primary_coordinator_affiliation_id', $affiliation->id)->orWhere('secondary_coordinator_affiliation_id', $affiliation->id))->exists());
    }

    public function viewAdvisorGrades(User $user, Internship $internship): bool
    {
        $affiliation = $this->context->selected($user);

        if ($affiliation?->type === AffiliationType::InternshipOffice) {
            return $this->view($user, $internship);
        }

        return $this->view($user, $internship)
            && in_array($affiliation->type, [AffiliationType::Advisor, AffiliationType::Coordinator], true)
            && $internship->advisorAffiliation?->user_id === $user->id
            && $internship->advisorAffiliation->campus_id === $affiliation->campus_id;
    }

    public function viewSupervisorEvaluation(User $user, Internship $internship): bool
    {
        return $this->view($user, $internship)
            && in_array($this->context->selected($user)->type, [
                AffiliationType::InternshipOffice, AffiliationType::Coordinator, AffiliationType::Advisor,
            ], true);
    }

    public function viewSupervisorEvaluationCriteria(User $user, Internship $internship): bool
    {
        return $this->viewSupervisorEvaluation($user, $internship)
            && in_array($this->context->selected($user)->type, [
                AffiliationType::InternshipOffice, AffiliationType::Coordinator,
            ], true);
    }

    public function viewSupervisorEvaluationInternalObservations(User $user, Internship $internship): bool
    {
        return $this->viewSupervisorEvaluation($user, $internship)
            && $this->context->selected($user)->type === AffiliationType::InternshipOffice;
    }

    public function updateAdvisorGrades(User $user, Internship $internship): bool
    {
        $affiliation = $this->context->selected($user);

        return $this->viewAdvisorGrades($user, $internship)
            && $affiliation->type === AffiliationType::Advisor
            && $internship->advisor_affiliation_id === $affiliation->id
            && $affiliation->campus->deactivated_at === null
            && $internship->course->deactivated_at === null
            && $internship->status !== InternshipStatus::Cancelled
            && $internship->advisorAffiliation->deactivated_at === null;
    }

    public function managePauses(User $user, Internship $internship): bool
    {
        return $this->update($user, $internship)
            && in_array($internship->status, [InternshipStatus::InProgress, InternshipStatus::Paused], true);
    }

    public function release(User $user, Internship $internship): bool
    {
        return $this->update($user, $internship) && $internship->course->deactivated_at === null
            && $internship->released_at === null
            && in_array($internship->status, [InternshipStatus::PendingFormalization, InternshipStatus::AwaitingSignatures], true);
    }

    public function viewLifecycle(User $user, Internship $internship): bool
    {
        return $this->view($user, $internship)
            && in_array($this->context->selected($user)->type, [AffiliationType::Student, AffiliationType::InternshipOffice], true);
    }

    public function complete(User $user, Internship $internship): bool
    {
        return $this->update($user, $internship) && $internship->course->deactivated_at === null
            && in_array($internship->status, [InternshipStatus::Released, InternshipStatus::InProgress, InternshipStatus::Paused], true);
    }

    public function cancel(User $user, Internship $internship): bool
    {
        return $this->update($user, $internship) && $internship->course->deactivated_at === null;
    }

    public function requestCancellation(User $user, Internship $internship): bool
    {
        return $this->view($user, $internship) && $this->context->student($user, writable: true) !== null
            && ! in_array($internship->status, [InternshipStatus::Completed, InternshipStatus::Cancelled], true);
    }

    public function withdrawCancellation(User $user, Internship $internship): bool
    {
        return $this->requestCancellation($user, $internship);
    }

    public function reviewCancellation(User $user, Internship $internship): bool
    {
        return $this->cancel($user, $internship);
    }

    public function updateAgreement(User $user, Internship $internship): bool
    {
        return $this->update($user, $internship) && $internship->released_at === null
            && in_array($internship->status, [InternshipStatus::PendingFormalization, InternshipStatus::AwaitingSignatures, InternshipStatus::PendingCorrection], true);
    }

    public function createPause(User $user, Internship $internship): bool
    {
        return $this->managePauses($user, $internship) && $internship->status === InternshipStatus::InProgress;
    }

    public function manageCalendar(User $user, Internship $internship): bool
    {
        return $this->update($user, $internship)
            && in_array($internship->status, [InternshipStatus::PendingFormalization, InternshipStatus::PendingCorrection,
                InternshipStatus::Released, InternshipStatus::InProgress, InternshipStatus::Paused], true);
    }

    public function applyWorkSchedule(User $user, Internship $internship): bool
    {
        return $this->update($user, $internship) && $internship->course->deactivated_at === null
            && in_array($internship->status, [InternshipStatus::Released, InternshipStatus::InProgress, InternshipStatus::Paused], true);
    }

    public function registerExternalDocument(User $user, Internship $internship): bool
    {
        return $this->update($user, $internship) && $internship->course->deactivated_at === null
            && $internship->status !== InternshipStatus::PendingCorrection;
    }

    public function viewCalculationHistory(User $user, Internship $internship): bool
    {
        return $this->view($user, $internship) && $this->context->selected($user)->type === AffiliationType::InternshipOffice;
    }

    public function generateDocument(User $user, Internship $internship, GeneratedDocumentType $type): bool
    {
        if (! $this->view($user, $internship)) {
            return false;
        }
        $affiliation = $this->context->selected($user);
        if ($affiliation->campus->deactivated_at !== null || $internship->course->deactivated_at !== null
            || in_array($internship->status, [InternshipStatus::PendingCorrection, InternshipStatus::Cancelled], true)) {
            return false;
        }

        return $type === GeneratedDocumentType::OrientationCertificate
            ? $affiliation->type === AffiliationType::Coordinator && $internship->course()->where(fn ($query) => $query
                ->where('primary_coordinator_affiliation_id', $affiliation->id)->orWhere('secondary_coordinator_affiliation_id', $affiliation->id))->exists()
            : $affiliation->type === AffiliationType::InternshipOffice;
    }
}
