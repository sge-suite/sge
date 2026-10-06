<?php

namespace App\Http\Requests;

class ReactivateAdministrativeAffiliationRequest extends AdministrativeAffiliationRequest
{
    protected function getRedirectUrl(): string
    {
        return route('users.show', ['user' => $this->route('user'), 'affiliation' => $this->route('affiliation')->id, 'operation' => 'reactivate']);
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
