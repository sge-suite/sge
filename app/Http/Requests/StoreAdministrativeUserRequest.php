<?php

namespace App\Http\Requests;

use App\Concerns\CampusAffiliationValidationRules;
use App\Models\User;
use App\Support\ActiveAffiliationContext;

class StoreAdministrativeUserRequest extends AdministrativeAffiliationRequest
{
    use CampusAffiliationValidationRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $user = is_string($this->input('cpf')) ? User::query()->where('cpf', $this->input('cpf'))->first() : null;

        $affiliationRules = $this->administrativeAffiliationRules($user);
        if ($this->user()->can('createOnCampus', User::class)) {
            $campusId = app(ActiveAffiliationContext::class)->currentFor($this->user(), app('session.store'))->campus_id;
            $affiliationRules = $this->campusAffiliationRules($campusId, $user);
        }

        return [...$this->administrativeAccountRules($user), ...$affiliationRules, 'consulted_cpf' => ['required', 'string', 'same:cpf']];
    }
}
