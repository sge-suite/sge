<?php

namespace App\Http\Requests;

class UpdateAdministrativeAffiliationRequest extends AdministrativeAffiliationRequest
{
    protected function ability(): string
    {
        return 'update';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->administrativeAffiliationEditableRules();
    }
}
