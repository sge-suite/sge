<?php

namespace App\Policies;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\User;
use App\Support\ActiveAffiliationContext;

class CampusPolicy
{
    public function __construct(private ActiveAffiliationContext $context) {}

    public function viewAny(User $user): bool
    {
        $affiliation = $this->activeAffiliation($user);

        return $affiliation?->type === AffiliationType::SystemAdministrator
            || ($affiliation?->type === AffiliationType::CampusAdministrator && $affiliation->campus_id !== null);
    }

    public function view(User $user, Campus $campus): bool
    {
        if ($campus->trashed()) {
            return false;
        }

        $affiliation = $this->activeAffiliation($user);

        return $affiliation?->type === AffiliationType::SystemAdministrator
            || ($affiliation?->type === AffiliationType::CampusAdministrator
                && $affiliation->campus_id === $campus->getKey());
    }

    public function create(User $user): bool
    {
        return $this->activeAffiliation($user)?->type === AffiliationType::SystemAdministrator;
    }

    public function update(User $user, Campus $campus): bool
    {
        if ($campus->deactivated_at !== null || $campus->trashed()) {
            return false;
        }

        $affiliation = $this->activeAffiliation($user);

        return $affiliation?->type === AffiliationType::SystemAdministrator
            || ($affiliation?->type === AffiliationType::CampusAdministrator
                && $affiliation->campus_id === $campus->getKey());
    }

    public function updateInstitutionalFields(User $user, Campus $campus): bool
    {
        return $this->update($user, $campus)
            && $this->activeAffiliation($user)?->type === AffiliationType::SystemAdministrator;
    }

    public function deactivate(User $user, Campus $campus): bool
    {
        return ! $campus->trashed()
            && $this->activeAffiliation($user)?->type === AffiliationType::SystemAdministrator;
    }

    public function reactivate(User $user, Campus $campus): bool
    {
        return ! $campus->trashed()
            && $this->activeAffiliation($user)?->type === AffiliationType::SystemAdministrator;
    }

    private function activeAffiliation(User $user): ?Affiliation
    {
        return $this->context->currentFor($user, request()->session());
    }
}
