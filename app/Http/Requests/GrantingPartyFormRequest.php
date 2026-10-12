<?php

namespace App\Http\Requests;

use App\Concerns\GrantingPartyValidationRules;
use App\Helpers\BrazilianAddressHelper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class GrantingPartyFormRequest extends FormRequest
{
    use GrantingPartyValidationRules;

    /** @return array<string, array<int, mixed>> */
    abstract public function rules(): array;

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('address'))) {
            $address = $this->input('address');
            $address['zip_code'] = BrazilianAddressHelper::cepForValidation($address['zip_code'] ?? null);
            $this->merge(['address' => $address]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->validateGrantingPartyFields($validator, $this->all(), [...array_keys($this->rules()), '_token', '_method']);
            if (array_key_exists('document_type', $this->rules())) {
                $this->validateGrantingPartyInput($validator, $this->all());
            }
        }];
    }
}
