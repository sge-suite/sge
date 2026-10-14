<?php

namespace App\Policies;

use App\Models\HolidaySource;
use App\Models\User;
use App\Support\HolidayAccess;

class HolidaySourcePolicy
{
    public function __construct(private HolidayAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canManage($user);
    }

    public function view(User $user, HolidaySource $source): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, HolidaySource $source): bool
    {
        return $this->viewAny($user);
    }
}
