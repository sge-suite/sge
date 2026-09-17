<?php

namespace App\Concerns;

use App\Enums\BrazilianState;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

trait CityValidationRules
{
    /**
     * @return array<string, array<int, Enum|string>>
     */
    protected function cityRules(): array
    {
        return [
            'ibge_code' => ['bail', 'required', 'string', 'digits:7'],
            'name' => ['bail', 'required', 'string', 'max:120'],
            'state' => ['bail', 'required', 'string', Rule::enum(BrazilianState::class)],
        ];
    }
}
