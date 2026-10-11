<?php

namespace App\Http\Requests;

class ReactivateInternshipTypeRequest extends InternshipTypeFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reactivate', $this->route('internshipType')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
