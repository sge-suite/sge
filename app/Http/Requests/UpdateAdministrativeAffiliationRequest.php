<?php

namespace App\Http\Requests;

use App\Concerns\CampusAffiliationValidationRules;
use App\Models\Affiliation;
use App\Models\User;

class UpdateAdministrativeAffiliationRequest extends AdministrativeAffiliationRequest
{
    use CampusAffiliationValidationRules;

    protected function ability(): string
    {
        return 'update';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var User $targetUser */
        $targetUser = $this->route('user');

        if ($this->user()->can('createOnCampus', User::class)) {
            /** @var Affiliation $affiliation */
            $affiliation = $this->route('affiliation');

            return $this->campusAffiliationEditableRules($affiliation, $targetUser);
        }

        return $this->administrativeAffiliationEditableRules($targetUser);
    }
}
