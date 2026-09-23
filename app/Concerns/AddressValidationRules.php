<?php

namespace App\Concerns;

use App\Models\City;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

trait AddressValidationRules
{
    /**
     * @return array<string, array<int, Exists|string>>
     */
    protected function addressRules(): array
    {
        return [
            'city_id' => ['bail', 'required', 'integer', Rule::exists(City::class, 'id')],
            'street' => ['required', 'string', 'max:255'],
            'number' => ['required', 'string', 'max:255'],
            'neighborhood' => ['required', 'string', 'max:255'],
        ];
    }
}
