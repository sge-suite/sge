<?php

namespace App\Http\Requests;

class UpdateCourseRequest extends CourseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('course')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->courseRules();
    }
}
