<?php

namespace App\Http\Requests;

class ReactivateCourseRequest extends CourseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reactivate', $this->route('course')) ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }
}
