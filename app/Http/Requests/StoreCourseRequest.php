<?php

namespace App\Http\Requests;

use App\Models\Course;

class StoreCourseRequest extends CourseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Course::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->courseRules();
    }
}
