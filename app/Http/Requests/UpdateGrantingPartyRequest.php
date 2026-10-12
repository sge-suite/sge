<?php

namespace App\Http\Requests;

class UpdateGrantingPartyRequest extends GrantingPartyFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('grantingParty')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->grantingPartyFormRules();
    }
}
