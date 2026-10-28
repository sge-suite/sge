<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UserManagementScope
{
    public function __construct(private ActiveAffiliationContext $context) {}

    public function searchScope(User $actor, string $role = 'all', string $status = 'all'): string
    {
        $selected = $this->context->currentFor($actor, app('session.store'));
        abort_unless($selected?->type === AffiliationType::SystemAdministrator
            || ($selected?->type === AffiliationType::InternshipOffice && $selected->campus_id !== null), 403);
        $role = $role === 'all' || array_key_exists($role, AffiliationType::options()) ? $role : 'invalid';
        $status = in_array($status, ['active', 'inactive'], true) ? $status : 'all';

        return User::affiliationSearchScope($selected->type === AffiliationType::SystemAdministrator
            ? 'administrative' : 'campus-'.$selected->campus_id, $role, $status);
    }

    /**
     * @param  Builder<Affiliation>  $query
     * @return Builder<Affiliation>
     */
    public function affiliations(Builder $query, User $actor): Builder
    {
        $selected = $this->context->currentFor($actor, app('session.store'));

        if ($selected?->type === AffiliationType::SystemAdministrator) {
            return $query->administrative();
        }

        abort_unless($selected?->type === AffiliationType::InternshipOffice && $selected->campus_id !== null, 403);

        return $query->where('campus_id', $selected->campus_id)->where('type', '!=', AffiliationType::SystemAdministrator->value);
    }
}
