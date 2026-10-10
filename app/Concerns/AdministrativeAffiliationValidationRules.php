<?php

namespace App\Concerns;

use App\Enums\AffiliationType;
use App\Models\Campus;
use App\Models\User;
use App\Rules\RegistrationNumberBelongsToUser;
use Illuminate\Validation\Rule;
use LaravelLegends\PtBrValidator\Rules\Cpf;

trait AdministrativeAffiliationValidationRules
{
    /** @return array<string, array<int, mixed>> */
    public function administrativeAffiliationRules(?User $user = null): array
    {
        return [
            'type' => ['required', Rule::in([AffiliationType::SystemAdministrator->value, AffiliationType::CampusAdministrator->value])],
            'campus_id' => ['bail', 'required_if:type,campus_administrator', 'prohibited_unless:type,campus_administrator', 'nullable', 'integer', Rule::exists(Campus::class, 'id')->whereNull('deactivated_at')->whereNull('deleted_at')],
            ...$this->administrativeAffiliationEditableRules($user),
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public function administrativeAffiliationEditableRules(?User $user = null): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'regex:/^[^@\s]+@[^@\s.]+(?:\.[^@\s.]+)+$/', 'max:255'],
            'registration_number' => ['bail', 'required', 'string', 'max:255', new RegistrationNumberBelongsToUser($user?->id)],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public function administrativeAccountRules(?User $existingUser): array
    {
        return [
            'cpf' => ['required', 'string', new Cpf],
            'name' => [$existingUser === null ? 'required' : 'nullable', 'string', 'max:255'],
        ];
    }
}
