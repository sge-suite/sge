<?php

namespace App\Http\Requests;

use App\Concerns\CampusAffiliationValidationRules;
use App\Models\Affiliation;
use App\Models\User;
use App\Support\ActiveAffiliationContext;

class StoreAdministrativeAffiliationRequest extends AdministrativeAffiliationRequest
{
    use CampusAffiliationValidationRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', [Affiliation::class, $this->route('user')]) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        if ($this->user()->can('createOnCampus', User::class)) {
            $campusId = app(ActiveAffiliationContext::class)->currentFor($this->user(), app('session.store'))->campus_id;

            return $this->campusAffiliationRules($campusId, $this->route('user'));
        }

        return $this->administrativeAffiliationRules($this->route('user'));
    }
}
