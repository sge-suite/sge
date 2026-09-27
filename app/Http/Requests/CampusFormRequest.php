<?php

namespace App\Http\Requests;

use App\Casts\PhoneCast;
use App\Models\Campus;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

abstract class CampusFormRequest extends FormRequest
{
    /**
     * @param  array<int, string>  $allowedKeys
     */
    protected function rejectUnexpectedKeys(Validator $validator, array $allowedKeys): void
    {
        $input = $this->all();
        $allowedKeys = [...$allowedKeys, '_token', '_method'];

        foreach (array_diff(array_keys($input), $allowedKeys) as $key) {
            $validator->errors()->add((string) $key, 'Este campo não pode ser enviado nesta operação.');
        }

        if (! is_array($input['address'] ?? null)) {
            return;
        }

        $allowedAddressKeys = ['city_id', 'street', 'number', 'neighborhood', 'zip_code'];

        foreach (array_diff(array_keys($input['address']), $allowedAddressKeys) as $key) {
            $validator->errors()->add("address.{$key}", 'Este campo não pode ser enviado nesta operação.');
        }
    }

    protected function phoneRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            try {
                (new PhoneCast)->set(new Campus, $attribute, $value, []);
            } catch (InvalidArgumentException) {
                $fail('Informe um telefone válido com DDD.');
            }
        };
    }

    /** @return array<string, array<int, string>> */
    protected function addressRules(bool $partial = false): array
    {
        $presence = $partial ? ['required_with:address'] : ['required'];

        return [
            'address.city_id' => [...$presence, 'integer', 'exists:cities,id'],
            'address.street' => [...$presence, 'string', 'max:255'],
            'address.number' => [...$presence, 'string', 'max:255'],
            'address.neighborhood' => [...$presence, 'string', 'max:255'],
            'address.zip_code' => ['nullable', 'string', 'max:255'],
        ];
    }
}
