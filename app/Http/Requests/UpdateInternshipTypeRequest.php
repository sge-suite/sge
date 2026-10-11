<?php

namespace App\Http\Requests;

class UpdateInternshipTypeRequest extends InternshipTypeFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('internshipType')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->formRules();
    }
}
