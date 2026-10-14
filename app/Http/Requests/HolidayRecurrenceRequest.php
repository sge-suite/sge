<?php

namespace App\Http\Requests;

use App\Models\HolidayRecurrence;
use Illuminate\Foundation\Http\FormRequest;

class HolidayRecurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = match ($this->route()->getName()) {
            'holiday-recurrences.destroy' => 'delete', 'holiday-recurrences.restore' => 'restore',
            default => $this->route('holidayRecurrence') === null ? 'create' : 'update',
        };

        return $this->user()?->can($ability, $this->route('holidayRecurrence') ?? HolidayRecurrence::class) ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return in_array($this->route()->getName(), ['holiday-recurrences.destroy', 'holiday-recurrences.restore'], true) ? [] : HolidayRecurrence::rules();
    }
}
