<?php

namespace App\Http\Requests;

use App\Casts\PhoneCast;
use App\Helpers\DigitsHelper;
use App\Models\Campus;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

abstract class CampusFormRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['cnpj', 'phone'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $normalized[$field] = DigitsHelper::only($value);
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome do campus.',
            'name.max' => 'O nome do campus não pode ultrapassar :max caracteres.',
            'cnpj.required' => 'Informe o CNPJ do campus.',
            'cnpj.cnpj' => 'O CNPJ informado é inválido.',
            'cnpj.unique' => 'Este CNPJ já está cadastrado em outro campus.',
            'phone.required' => 'Informe o telefone do campus.',
            'phone.unique' => 'Este telefone já está cadastrado em outro campus.',
            'legal_representative_name.required' => 'Informe o nome do representante legal.',
            'legal_representative_position.required' => 'Informe o cargo do representante legal.',
            'insurance_company_name.required' => 'Informe a seguradora.',
            'insurance_policy_number.required' => 'Informe o número da apólice.',
            'address.city_id.required' => 'Selecione a cidade do campus.',
            'address.city_id.required_with' => 'Selecione a cidade do campus.',
            'address.city_id.integer' => 'Selecione uma cidade válida.',
            'address.city_id.exists' => 'A cidade selecionada não está cadastrada.',
            'address.street.required' => 'Informe o logradouro.',
            'address.street.required_with' => 'Informe o logradouro.',
            'address.number.required' => 'Informe o número do endereço.',
            'address.number.required_with' => 'Informe o número do endereço.',
            'address.neighborhood.required' => 'Informe o bairro.',
            'address.neighborhood.required_with' => 'Informe o bairro.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nome do campus',
            'cnpj' => 'CNPJ',
            'phone' => 'telefone',
            'legal_representative_name' => 'nome do representante legal',
            'legal_representative_position' => 'cargo do representante legal',
            'insurance_company_name' => 'seguradora',
            'insurance_policy_number' => 'número da apólice',
            'address.city_id' => 'cidade',
            'address.street' => 'logradouro',
            'address.number' => 'número do endereço',
            'address.neighborhood' => 'bairro',
            'address.zip_code' => 'CEP',
            'current_password' => 'senha atual',
        ];
    }

    /** @return list<Unique> */
    protected function uniqueCampusValueWhenChanged(string $field, Campus $campus): array
    {
        if (! array_key_exists($field, $this->all()) || $this->input($field) === $campus->{$field}) {
            return [];
        }

        return [Rule::unique(Campus::class, $field)->ignore($campus)];
    }

    /**
     * @param  array<int, string>  $allowedKeys
     */
    protected function rejectUnexpectedKeys(Validator $validator, array $allowedKeys): void
    {
        $input = $this->all();
        $allowedKeys = [...$allowedKeys, '_token', '_method', 'citySearch'];

        foreach (array_diff(array_keys($input), $allowedKeys) as $key) {
            $validator->errors()->add((string) $key, 'O formulário contém um campo não permitido.');
        }

        if (! is_array($input['address'] ?? null)) {
            return;
        }

        $allowedAddressKeys = ['city_id', 'street', 'number', 'neighborhood', 'zip_code'];

        foreach (array_diff(array_keys($input['address']), $allowedAddressKeys) as $key) {
            $validator->errors()->add("address.{$key}", 'O endereço contém um campo não permitido.');
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
            'address.city_id' => ['bail', ...$presence, 'integer', 'exists:cities,id'],
            'address.street' => [...$presence, 'string', 'max:255'],
            'address.number' => [...$presence, 'string', 'max:255'],
            'address.neighborhood' => [...$presence, 'string', 'max:255'],
            'address.zip_code' => ['nullable', 'string', 'max:255'],
        ];
    }
}
