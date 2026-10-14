<?php

namespace App\Http\Requests;

use App\Models\Holiday;
use Illuminate\Foundation\Http\FormRequest;

class HolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = match ($this->route()->getName()) {
            'holidays.store' => 'create', 'holidays.update' => 'update', 'holidays.destroy' => 'delete', 'holidays.restore' => 'restore', default => '',
        };

        return $this->user()?->can($ability, $this->route('holiday') ?? Holiday::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name')]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return in_array($this->route()->getName(), ['holidays.store', 'holidays.update'], true) ? Holiday::inputRules() : [];
    }
}
