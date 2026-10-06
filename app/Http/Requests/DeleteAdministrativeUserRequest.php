<?php

namespace App\Http\Requests;

class DeleteAdministrativeUserRequest extends AdministrativeAffiliationRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delete', $this->route('user')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['confirmed' => ['accepted'], 'current_password' => ['required', 'string', 'current_password']];
    }

    protected function getRedirectUrl(): string
    {
        return route('users.show', ['user' => $this->route('user'), 'operation' => 'delete_account']);
    }
}
