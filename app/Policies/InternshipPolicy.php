<?php

namespace App\Policies;

use App\Enums\AffiliationType;
use App\Enums\GeneratedDocumentType;
use App\Enums\InternshipStatus;
use App\Models\Course;
use App\Models\Internship;
use App\Models\User;
use App\Support\StudentInternshipContext;

class InternshipPolicy
{
    public function __construct(private StudentInternshipContext $context) {}

    public function viewAny(User $user): bool
    {
        $affiliation = $this->context->selected($user);

        return $affiliation?->campus !== null && ($affiliation->type === AffiliationType::InternshipOffice
            || ($affiliation->type === AffiliationType::Coordinator && Course::query()->where('campus_id', $affiliation->campus_id)
                ->where(fn ($query) => $query->where('primary_coordinator_affiliation_id', $affiliation->id)
                    ->orWhere('secondary_coordinator_affiliation_id', $affiliation->id))->exists()));
    }

    public function view(User $user, Internship $internship): bool
    {
        $affiliation = $this->context->selected($user);

        return $this->viewAny($user) && $internship->course()->where('campus_id', $affiliation->campus_id)
            ->when($affiliation->type === AffiliationType::Coordinator, fn ($query) => $query->where(fn ($query) => $query
                ->where('primary_coordinator_affiliation_id', $affiliation->id)->orWhere('secondary_coordinator_affiliation_id', $affiliation->id)))->exists();
    }

    public function update(User $user, Internship $internship): bool
    {
        return $this->view($user, $internship)
            && $this->context->selected($user)->type === AffiliationType::InternshipOffice
            && $this->context->selected($user)->campus->deactivated_at === null
            && ! in_array($internship->status, [InternshipStatus::Completed, InternshipStatus::Cancelled], true);
    }

    public function managePauses(User $user, Internship $internship): bool
    {
        return $this->update($user, $internship)
            && in_array($internship->status, [InternshipStatus::InProgress, InternshipStatus::Paused], true);
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
            ? $affiliation->type === AffiliationType::Coordinator
            : $affiliation->type === AffiliationType::InternshipOffice;
    }
}
