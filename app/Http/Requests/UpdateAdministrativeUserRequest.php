<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Validation\Rule;
use LaravelLegends\PtBrValidator\Rules\Cpf;

class UpdateAdministrativeUserRequest extends AdministrativeAffiliationRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'cpf' => ['required', 'string', new Cpf, Rule::unique(User::class, 'cpf')->ignore($user)],
            'email' => $this->administrativeAffiliationEditableRules()['email'],
        ];
    }
}
