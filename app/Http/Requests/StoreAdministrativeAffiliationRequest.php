<?php

namespace App\Http\Requests;

class StoreAdministrativeAffiliationRequest extends AdministrativeAffiliationRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->administrativeAffiliationRules();
    }
}
