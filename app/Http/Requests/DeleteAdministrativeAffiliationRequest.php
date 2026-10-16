<?php

namespace App\Http\Requests;

use App\Models\Affiliation;

class DeleteAdministrativeAffiliationRequest extends AdministrativeAffiliationRequest
{
    protected function ability(): string
    {
        return 'delete';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['confirmed' => ['accepted'], 'current_password' => ['required', 'string', 'current_password']];
    }

    protected function getRedirectUrl(): string
    {
        /** @var Affiliation $affiliation */
        $affiliation = $this->route('affiliation');

        return route('users.show', ['user' => $this->route('user'), 'affiliation' => $affiliation->id, 'operation' => 'delete']);
    }
}
