<?php

namespace App\Policies;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\GrantingParty;
use App\Models\User;
use App\Support\ActiveAffiliationContext;

class GrantingPartyPolicy
{
    public function __construct(private ActiveAffiliationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->internshipOffice($user) !== null;
    }

    public function view(User $user, GrantingParty $grantingParty): bool
    {
        $affiliation = $this->internshipOffice($user);

        return $affiliation !== null && $grantingParty->exists && $affiliation->campus_id === $grantingParty->campus_id;
    }

    public function create(User $user): bool
    {
        $affiliation = $this->internshipOffice($user);

        return $affiliation !== null && $affiliation->campus->deactivated_at === null;
    }

    public function update(User $user, GrantingParty $grantingParty): bool
    {
        return $this->view($user, $grantingParty) && $this->create($user);
    }

    public function deactivate(User $user, GrantingParty $grantingParty): bool
    {
        return $this->update($user, $grantingParty) && $grantingParty->deactivated_at === null;
    }

    public function reactivate(User $user, GrantingParty $grantingParty): bool
    {
        return $this->update($user, $grantingParty) && $grantingParty->deactivated_at !== null;
    }

    public function delete(User $user, GrantingParty $grantingParty): bool
    {
        return $this->update($user, $grantingParty) && ! $grantingParty->hasLinkedRecords();
    }

    private function internshipOffice(User $user): ?Affiliation
    {
        $affiliation = $this->context->currentFor($user, app('session.store'));

        if ($affiliation?->type !== AffiliationType::InternshipOffice || $affiliation->campus_id === null) {
            return null;
        }

        $affiliation->load('campus');

        return $affiliation->campus === null ? null : $affiliation;
    }
}
