<?php

namespace App\Policies;

use App\Models\Affiliation;
use App\Models\User;
use App\Support\ActiveAffiliationContext;

class AffiliationPolicy
{
    public function __construct(private ActiveAffiliationContext $context) {}

    public function viewNotifications(User $user, Affiliation $affiliation): bool
    {
        return $this->context->currentFor($user, app('session.store'))?->is($affiliation) === true;
    }
}
