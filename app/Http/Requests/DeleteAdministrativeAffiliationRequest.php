<?php

namespace App\Http\Requests;

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
        return route('users.show', ['user' => $this->route('user'), 'affiliation' => $this->route('affiliation')->id, 'operation' => 'delete']);
    }
}
