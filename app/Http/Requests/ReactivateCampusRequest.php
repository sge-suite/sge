<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class ReactivateCampusRequest extends CampusFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('reactivate', $this->route('campus')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->rejectUnexpectedKeys($validator, []);
        }];
    }
}
