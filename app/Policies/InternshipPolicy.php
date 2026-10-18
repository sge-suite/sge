<?php

namespace App\Policies;

use App\Enums\AffiliationType;
use App\Enums\InternshipStatus;
use App\Models\Internship;
use App\Models\User;
use App\Support\StudentInternshipContext;

class InternshipPolicy
{
    public function __construct(private StudentInternshipContext $context) {}

    public function viewAny(User $user): bool
    {
        $affiliation = $this->context->selected($user);

        return $affiliation?->type === AffiliationType::InternshipOffice && $affiliation->campus !== null;
    }

    public function view(User $user, Internship $internship): bool
    {
        $affiliation = $this->context->selected($user);

        return $this->viewAny($user) && $internship->course()->where('campus_id', $affiliation->campus_id)->exists();
    }

    public function update(User $user, Internship $internship): bool
    {
        return $this->view($user, $internship)
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
}
