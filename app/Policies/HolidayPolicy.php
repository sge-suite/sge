<?php

namespace App\Policies;

use App\Models\Holiday;
use App\Models\User;
use App\Support\HolidayAccess;

class HolidayPolicy
{
    public function __construct(private HolidayAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canView($user);
    }

    public function view(User $user, Holiday $holiday): bool
    {
        return $this->access->query($user, true)->whereKey($holiday->id)->exists();
    }

    public function create(User $user): bool
    {
        return $this->access->canManage($user);
    }

    public function update(User $user, Holiday $holiday): bool
    {
        return $this->access->canManage($user) && ! $holiday->trashed();
    }

    public function delete(User $user, Holiday $holiday): bool
    {
        return $this->update($user, $holiday);
    }

    public function restore(User $user, Holiday $holiday): bool
    {
        return $this->access->canManage($user) && $holiday->trashed();
    }
}
