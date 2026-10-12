<?php

namespace App\Http\Requests;

use App\Models\GrantingParty;

class StoreGrantingPartyRequest extends GrantingPartyFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', GrantingParty::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->grantingPartyFormRules();
    }
}
