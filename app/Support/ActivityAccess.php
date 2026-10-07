<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Session\Store;
use Spatie\Activitylog\Models\Activity;

class ActivityAccess
{
    public function __construct(
        private ActiveAffiliationContext $context,
        private AdministrativeActivityScope $administrativeScope,
    ) {}

    /**
     * Return the activity query permitted by the currently selected affiliation.
     * Add future affiliation-specific visibility rules here, without bypassing policies.
     *
     * @return Builder<Activity>|null
     */
    public function forCurrentContext(User $user, Store $session): ?Builder
    {
        $affiliation = $this->context->currentFor($user, $session);

        if ($affiliation === null) {
            return null;
        }

        return match ($affiliation->type) {
            AffiliationType::SystemAdministrator => $this->administrativeScope->apply(Activity::query()),
            default => null,
        };
    }
}
