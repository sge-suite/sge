<?php

namespace App\Policies;

use App\Models\HolidayRecurrence;
use App\Models\User;
use App\Support\HolidayAccess;

class HolidayRecurrencePolicy
{
    public function __construct(private HolidayAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canView($user);
    }

    public function view(User $user, HolidayRecurrence $recurrence): bool
    {
        return $this->access->recurrences($user, true)->whereKey($recurrence->id)->exists();
    }

    public function create(User $user): bool
    {
        return $this->access->canManage($user);
    }

    public function update(User $user, HolidayRecurrence $recurrence): bool
    {
        return $this->access->canManage($user) && ! $recurrence->trashed();
    }

    public function delete(User $user, HolidayRecurrence $recurrence): bool
    {
        return $this->update($user, $recurrence);
    }

    public function restore(User $user, HolidayRecurrence $recurrence): bool
    {
        return $this->access->canManage($user) && $recurrence->trashed();
    }
}
