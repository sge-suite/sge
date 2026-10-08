<?php

namespace App\Policies;

use App\Models\EmailDeliveryAttempt;
use App\Models\User;
use App\Support\EmailLogAccess;

class EmailDeliveryAttemptPolicy
{
    public function __construct(private EmailLogAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->forCurrentContext($user, app('session.store')) !== null;
    }

    public function view(User $user, EmailDeliveryAttempt $attempt): bool
    {
        return $this->access->forCurrentContext($user, app('session.store'))?->whereKey($attempt->id)->exists() === true;
    }
}
