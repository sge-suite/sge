<?php

namespace App\Policies;

use App\Models\Affiliation;
use App\Models\User;

class AffiliationPolicy
{
    public function viewNotifications(User $user, Affiliation $affiliation): bool
    {
        return Affiliation::query()
            ->active()
            ->whereKey($affiliation->getKey())
            ->whereBelongsTo($user, 'user')
            ->exists();
    }
}
