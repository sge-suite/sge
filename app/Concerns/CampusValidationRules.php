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
            'cnpj' => ['nullable', 'string', 'size:14'],
            'phone' => ['nullable', 'string'],
            'email' => ['nullable', 'string', 'email'],
            'address_id' => ['bail', 'required', 'integer', Rule::exists(Address::class, 'id')],
            'legal_representative_name' => ['nullable', 'string', 'max:255'],
            'legal_representative_position' => ['nullable', 'string', 'max:255'],
            'insurance_company_name' => ['nullable', 'string', 'max:255'],
            'insurance_policy_number' => ['nullable', 'string'],
            'deactivated_at' => ['nullable', 'date'],
        ];
    }

    protected function nullifyBlankOptionalCampusValues(): void
    {
        foreach ([
            'cnpj',
            'phone',
            'email',
            'legal_representative_name',
            'legal_representative_position',
            'insurance_company_name',
            'insurance_policy_number',
            'deactivated_at',
        ] as $attribute) {
            if (blank($this->getAttributes()[$attribute] ?? null)) {
                $this->{$attribute} = null;
            }
        }
    }
}
