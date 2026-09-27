<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class DeactivateCampusRequest extends CampusFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('deactivate', $this->route('campus')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->rejectUnexpectedKeys($validator, ['current_password']);
        }];
    }
}
