<?php

namespace App\Http\Requests;

use App\Models\InternshipType;

class StoreInternshipTypeRequest extends InternshipTypeFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', InternshipType::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->formRules();
    }
}
