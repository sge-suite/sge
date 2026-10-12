<?php

namespace App\Http\Requests;

class DeactivateGrantingPartyRequest extends GrantingPartyFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('deactivate', $this->route('grantingParty')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['current_password' => ['required', 'string', 'current_password']];
    }
}
