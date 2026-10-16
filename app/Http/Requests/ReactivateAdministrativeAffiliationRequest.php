<?php

namespace App\Http\Requests;

use App\Models\Affiliation;

class ReactivateAdministrativeAffiliationRequest extends AdministrativeAffiliationRequest
{
    protected function getRedirectUrl(): string
    {
        /** @var Affiliation $affiliation */
        $affiliation = $this->route('affiliation');

        return route('users.show', ['user' => $this->route('user'), 'affiliation' => $affiliation->id, 'operation' => 'reactivate']);
    }

    protected function ability(): string
    {
        return 'reactivate';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['confirmed' => ['accepted']];
    }
}
