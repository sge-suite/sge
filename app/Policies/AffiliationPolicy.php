<?php

namespace App\Policies;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\User;
use App\Support\ActiveAffiliationContext;

class AffiliationPolicy
{
    public function __construct(private ActiveAffiliationContext $context) {}

    public function create(User $user): bool
    {
        return $this->context->currentFor($user, app('session.store'))?->type === AffiliationType::SystemAdministrator;
    }

    public function view(User $user, Affiliation $affiliation): bool
    {
        return $this->create($user) && in_array($affiliation->type, [AffiliationType::SystemAdministrator, AffiliationType::CampusAdministrator], true);
    }

    public function update(User $user, Affiliation $affiliation): bool
    {
        return $this->view($user, $affiliation)
            && ($affiliation->campus_id === null || $affiliation->campus()->whereNull('deactivated_at')->exists());
    }

    public function deactivate(User $user, Affiliation $affiliation): bool
    {
        return $this->update($user, $affiliation) && $affiliation->deactivated_at === null
            && $this->context->currentFor($user, app('session.store'))?->id !== $affiliation->id;
    }

    public function delete(User $user, Affiliation $affiliation): bool
    {
        return $this->update($user, $affiliation)
            && $this->context->currentFor($user, app('session.store'))?->id !== $affiliation->id;
    }

    public function reactivate(User $user, Affiliation $affiliation): bool
    {
        return $this->update($user, $affiliation) && $affiliation->deactivated_at !== null;
    }

    public function viewNotifications(User $user, Affiliation $affiliation): bool
    {
        return $this->context->currentFor($user, app('session.store'))?->is($affiliation) === true;
    }
}
