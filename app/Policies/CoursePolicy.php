<?php

namespace App\Policies;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Course;
use App\Models\User;
use App\Support\ActiveAffiliationContext;

class CoursePolicy
{
    public function __construct(private ActiveAffiliationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->administrator($user) !== null;
    }

    public function view(User $user, Course $course): bool
    {
        $affiliation = $this->administrator($user);

        return $affiliation !== null && $affiliation->campus_id === $course->campus_id;
    }

    public function create(User $user): bool
    {
        $affiliation = $this->administrator($user);

        return $affiliation !== null && $affiliation->campus->deactivated_at === null;
    }

    public function update(User $user, Course $course): bool
    {
        return $this->view($user, $course) && $this->create($user);
    }

    public function deactivate(User $user, Course $course): bool
    {
        return $this->update($user, $course) && $course->deactivated_at === null;
    }

    public function reactivate(User $user, Course $course): bool
    {
        return $this->update($user, $course) && $course->deactivated_at !== null;
    }

    public function delete(User $user, Course $course): bool
    {
        return $this->update($user, $course);
    }

    private function administrator(User $user): ?Affiliation
    {
        $affiliation = $this->context->currentFor($user, app('session.store'));

        if ($affiliation?->type !== AffiliationType::CampusAdministrator || $affiliation->campus_id === null) {
            return null;
        }

        $affiliation->load('campus');

        return $affiliation->campus === null ? null : $affiliation;
    }
}
