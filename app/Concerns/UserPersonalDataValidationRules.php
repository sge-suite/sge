<?php

namespace App\Concerns;

use App\Models\Address;
use App\Models\User;
use Illuminate\Validation\Rule;

trait UserPersonalDataValidationRules
{
    use SupervisorProfessionalDataValidationRules;

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function userPersonalDataRules(): array
    {
        return [
            'user_id' => ['bail', 'required', 'integer', Rule::exists(User::class, 'id')],
            'rg' => ['nullable', 'string', 'max:255', 'required_with:rg_issuer,rg_issue_date'],
            'rg_issuer' => ['nullable', 'string', 'max:255', 'required_with:rg,rg_issue_date'],
            'rg_issue_date' => ['nullable', 'date', 'before_or_equal:today', 'required_with:rg,rg_issuer'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'phone' => ['nullable', 'string', 'max:255'],
            ...$this->supervisorProfessionalReferenceRules(),
            'address_id' => ['nullable', 'integer', Rule::exists(Address::class, 'id')],
        ];
    }
}
