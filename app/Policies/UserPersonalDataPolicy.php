<?php

namespace App\Policies;

use App\Enums\AffiliationType;
use App\Models\User;
use App\Models\UserPersonalData;
use App\Support\ActiveAffiliationContext;

class UserPersonalDataPolicy
{
    public function __construct(private ActiveAffiliationContext $context) {}

    public function viewOwn(User $user): bool
    {
        return $this->updateStudentData($user) || $this->updateProfessionalData($user);
    }

    public function create(User $user): bool
    {
        return $this->viewOwn($user);
    }

    public function update(User $user, UserPersonalData $personalData): bool
    {
        return $personalData->user_id === $user->id && $this->viewOwn($user);
    }

    public function updateStudentData(User $user): bool
    {
        return $this->context->currentFor($user, app('session.store'))?->type === AffiliationType::Student;
    }

    public function updateProfessionalData(User $user): bool
    {
        return $this->context->currentFor($user, app('session.store'))?->type === AffiliationType::Supervisor;
    }
}
