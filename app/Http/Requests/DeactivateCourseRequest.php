<?php

namespace App\Http\Requests;

class DeactivateCourseRequest extends CourseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('deactivate', $this->route('course')) ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }
}
