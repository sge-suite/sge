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
        return $this->context->currentFor($user, app('session.store'))?->type === AffiliationType::SystemAdministrator;
    }

    public function view(User $user, User $target): bool
    {
        return $this->viewAny($user) && ($target->affiliations()->administrative()->exists() || ! $target->affiliations()->exists());
    }

    public function update(User $user, User $target): bool
    {
        return $this->view($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        return $this->view($user, $target) && $user->id !== $target->id;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }
}
