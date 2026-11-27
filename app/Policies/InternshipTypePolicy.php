<?php

namespace App\Policies;

use App\Models\InternshipType;
use App\Models\User;

class InternshipTypePolicy
{
    public function __construct(private CoursePolicy $coursePolicy) {}

    public function viewAny(User $user): bool
    {
        return $this->coursePolicy->viewAny($user);
    }

    public function view(User $user, InternshipType $internshipType): bool
    {
        if (! $internshipType->exists) {
            return false;
        }

        $internshipType->loadMissing('course');

        return $this->coursePolicy->view($user, $internshipType->course);
    }

    public function create(User $user): bool
    {
        return $this->coursePolicy->create($user);
    }

    public function update(User $user, InternshipType $internshipType): bool
    {
        return $this->view($user, $internshipType) && $this->create($user);
    }

    public function deactivate(User $user, InternshipType $internshipType): bool
    {
        return $this->update($user, $internshipType) && $internshipType->deactivated_at === null;
    }

    public function reactivate(User $user, InternshipType $internshipType): bool
    {
        return $this->update($user, $internshipType) && $internshipType->deactivated_at !== null;
    }

    public function delete(User $user, InternshipType $internshipType): bool
    {
        return $this->update($user, $internshipType) && ! $internshipType->hasLinkedRecords();
    }
}
