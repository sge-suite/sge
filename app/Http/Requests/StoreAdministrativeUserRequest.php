<?php

namespace App\Http\Requests;

use App\Models\User;

class StoreAdministrativeUserRequest extends AdministrativeAffiliationRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $user = is_string($this->input('cpf')) ? User::query()->where('cpf', $this->input('cpf'))->first() : null;

        return [...$this->administrativeAccountRules($user), ...$this->administrativeAffiliationRules($user), 'consulted_cpf' => ['required', 'string', 'same:cpf']];
    }
}
