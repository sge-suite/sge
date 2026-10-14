<?php

namespace App\Http\Requests;

use App\Models\HolidaySource;
use Illuminate\Foundation\Http\FormRequest;

class ResolveHolidaySourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('holidaySource')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return HolidaySource::resolutionRules($this->all());
    }
}
