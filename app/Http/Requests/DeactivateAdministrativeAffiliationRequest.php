<?php

namespace App\Http\Requests;

use App\Models\Affiliation;

class DeactivateAdministrativeAffiliationRequest extends AdministrativeAffiliationRequest
{
    protected function getRedirectUrl(): string
    {
        /** @var Affiliation $affiliation */
        $affiliation = $this->route('affiliation');

        return route('users.show', ['user' => $this->route('user'), 'affiliation' => $affiliation->id, 'operation' => 'deactivate']);
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
