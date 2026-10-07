<?php

namespace App\Policies;

use App\Models\User;
use App\Support\ActivityAccess;
use Spatie\Activitylog\Models\Activity;

class ActivityPolicy
{
    public function __construct(
        private ActivityAccess $access,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->access->forCurrentContext($user, app('session.store')) !== null;
    }

    public function view(User $user, Activity $activity): bool
    {
        $query = $this->access->forCurrentContext($user, app('session.store'));

        return $query?->whereKey($activity->getKey())->exists() === true;
    }
}
