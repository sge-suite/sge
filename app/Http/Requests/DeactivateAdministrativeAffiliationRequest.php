<?php

namespace App\Http\Requests;

class DeactivateAdministrativeAffiliationRequest extends AdministrativeAffiliationRequest
{
    protected function getRedirectUrl(): string
    {
        return route('users.show', ['user' => $this->route('user'), 'affiliation' => $this->route('affiliation')->id, 'operation' => 'deactivate']);
    }

    protected function ability(): string
    {
        return 'deactivate';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['current_password' => ['required', 'string', 'current_password'], 'confirmed' => ['accepted']];
    }
}
