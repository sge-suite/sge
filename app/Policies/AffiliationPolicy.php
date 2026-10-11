<?php

namespace App\Policies;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\User;
use App\Support\ActiveAffiliationContext;

class AffiliationPolicy
{
    public function __construct(private ActiveAffiliationContext $context) {}

    public function create(User $user, ?User $target = null): bool
    {
        return $user->can('viewAdministration', User::class)
            || ($user->can('createOnCampus', User::class) && ($target === null || $user->can('view', $target)));
    }

    public function view(User $user, Affiliation $affiliation): bool
    {
        return $user->can('viewAdministration', User::class) && in_array($affiliation->type, [AffiliationType::SystemAdministrator, AffiliationType::InternshipOffice], true);
    }

    public function update(User $user, Affiliation $affiliation): bool
    {
        $canEditOnCampus = $user->can('createOnCampus', User::class)
            && $affiliation->type !== AffiliationType::SystemAdministrator
            && $affiliation->campus_id === $this->context->currentFor($user, app('session.store'))?->campus_id;

        return ($this->view($user, $affiliation) || $canEditOnCampus)
            && ($affiliation->campus_id === null || $affiliation->campus()->whereNull('deactivated_at')->exists());
    }

    public function deactivate(User $user, Affiliation $affiliation): bool
    {
        return $this->canManageOtherAffiliation($user, $affiliation) && $affiliation->deactivated_at === null;
    }

    public function delete(User $user, Affiliation $affiliation): bool
    {
        return $this->canManageOtherAffiliation($user, $affiliation);
    }

    public function reactivate(User $user, Affiliation $affiliation): bool
    {
        return $user->can('viewAdministration', User::class) && $this->update($user, $affiliation) && $affiliation->deactivated_at !== null;
    }

    public function viewNotifications(User $user, Affiliation $affiliation): bool
    {
        return $this->context->currentFor($user, app('session.store'))?->is($affiliation) === true;
    }

    private function canManageOtherAffiliation(User $user, Affiliation $affiliation): bool
    {
        if ($affiliation->user_id === $user->id) {
            return false;
        }

        return $this->update($user, $affiliation);
    }
}
