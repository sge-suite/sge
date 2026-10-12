<?php

namespace App\Http\Requests;

class ReactivateGrantingPartyRequest extends GrantingPartyFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reactivate', $this->route('grantingParty')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
