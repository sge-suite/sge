<?php

namespace App\Policies;

use App\Enums\AffiliationType;
use App\Models\User;
use App\Support\ActiveAffiliationContext;

class UserPolicy
{
    public function __construct(private ActiveAffiliationContext $context) {}

    public function viewAny(User $user): bool
    {
        $affiliation = $this->context->currentFor($user, app('session.store'));

        return $this->viewAdministration($user)
            || ($affiliation?->type === AffiliationType::InternshipOffice && $affiliation->campus()->exists());
    }

    public function viewAdministration(User $user): bool
    {
        return $this->context->currentFor($user, app('session.store'))?->type === AffiliationType::SystemAdministrator;
    }

    public function view(User $user, User $target): bool
    {
        if ($this->viewAdministration($user)) {
            return $target->affiliations()->administrative()->exists() || ! $target->affiliations()->exists();
        }
        $affiliation = $this->context->currentFor($user, app('session.store'));

        return $this->viewAny($user) && $target->affiliations()->where('campus_id', $affiliation->campus_id)
            ->where('type', '!=', AffiliationType::SystemAdministrator->value)->exists();
    }

    public function update(User $user, User $target): bool
    {
        return $this->view($user, $target) && ($this->viewAdministration($user)
            || ($this->createOnCampus($user) && ($user->is($target)
                || ! $target->affiliations()->where('type', AffiliationType::SystemAdministrator)->exists())));
    }

    public function delete(User $user, User $target): bool
    {
        return $this->viewAdministration($user) && $this->update($user, $target) && $user->id !== $target->id;
    }

    public function create(User $user): bool
    {
        return $this->viewAdministration($user) || $this->createOnCampus($user);
    }

    public function createOnCampus(User $user): bool
    {
        $affiliation = $this->context->currentFor($user, app('session.store'));

        return $affiliation?->type === AffiliationType::InternshipOffice
            && $affiliation->campus()->whereNull('deactivated_at')->exists();
    }
}
