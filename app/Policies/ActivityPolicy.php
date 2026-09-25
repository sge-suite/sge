<?php

namespace App\Policies;

use App\Enums\AffiliationType;
use App\Models\User;
use App\Support\ActiveAffiliationContext;
use Spatie\Activitylog\Models\Activity;

class ActivityPolicy
{
    public function __construct(private ActiveAffiliationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->currentFor($user, app('session.store'))?->type === AffiliationType::SystemAdministrator;
    }

    public function view(User $user, Activity $activity): bool
    {
        return $this->viewAny($user);
    }
}
