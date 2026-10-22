<?php

namespace App\Concerns;

use App\Models\Address;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

trait CampusValidationRules
{
    /**
     * @return array<string, array<int, Exists|string>>
     */
    protected function campusRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'cnpj' => ['required', 'string', 'size:14'],
            'phone' => ['required', 'string', 'max:255'],
            'internship_office_email' => ['required', 'string', 'email', 'max:254'],
            'address_id' => ['bail', 'required', 'integer', Rule::exists(Address::class, 'id')],
            'legal_representative_name' => ['required', 'string', 'max:255'],
            'legal_representative_position' => ['required', 'string', 'max:255'],
            'insurance_company_name' => ['required', 'string', 'max:255'],
            'insurance_policy_number' => ['required', 'string', 'max:255'],
            'deactivated_at' => ['nullable', 'date'],
        ];
    }

    protected function normalizeBlankCampusValues(): void
    {
        if (is_string($this->internship_office_email)) {
            $this->internship_office_email = mb_strtolower(trim($this->internship_office_email));
        }

        foreach ([
            'cnpj',
            'phone',
            'internship_office_email',
            'legal_representative_name',
            'legal_representative_position',
            'insurance_company_name',
            'insurance_policy_number',
            'deactivated_at',
        ] as $attribute) {
            if (blank($this->getAttributes()[$attribute] ?? null)) {
                $this->setAttribute($attribute, null);
            }
        }
    }
}
