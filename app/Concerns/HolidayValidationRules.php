<?php

namespace App\Concerns;

use App\Enums\BrazilianState;
use App\Enums\HolidayScope;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

trait HolidayValidationRules
{
    /**
     * @return array<string, array<int, Enum|string>>
     */
    protected function holidayRules(): array
    {
        return [
            'date' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'name' => ['bail', 'required', 'string', 'max:255'],
            'scope' => ['bail', 'required', Rule::enum(HolidayScope::class)],
            'state_code' => ['bail', 'nullable', Rule::enum(BrazilianState::class)],
            'city_id' => ['bail', 'nullable', 'integer'],
        ];
    }
}
