<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UserManagementScope
{
    public function __construct(private ActiveAffiliationContext $context) {}

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
