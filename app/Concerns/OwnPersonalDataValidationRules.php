<?php

namespace App\Concerns;

use App\Casts\PhoneCast;
use App\Enums\AffiliationType;
use App\Helpers\BrazilianAddressHelper;
use App\Models\UserPersonalData;
use Closure;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

trait OwnPersonalDataValidationRules
{
    use AddressValidationRules;
    use UserPersonalDataValidationRules;

    /** @return list<string> */
    protected function ownPersonalDataFields(AffiliationType $type): array
    {
        return match ($type) {
            AffiliationType::Student => ['rg', 'rg_issuer', 'rg_issue_date', 'birth_date', 'phone'],
            AffiliationType::Supervisor => [...array_keys($this->supervisorProfessionalReferenceRules()), 'phone'],
            default => [],
        };
    }

    /** @return array<string, array<int, mixed>> */
    protected function ownPersonalDataRules(AffiliationType $type, bool $partial = true): array
    {
        $rules = [];
        foreach ($this->ownPersonalDataFields($type) as $field) {
            $fieldRules = $this->userPersonalDataRules()[$field];
            if ($partial) {
                $fieldRules = array_values(array_filter($fieldRules, fn (mixed $rule): bool => ! is_string($rule) || ! str_starts_with($rule, 'required_with:')));
            }
            $rules[$field] = ['sometimes', ...$fieldRules];
        }
        if (isset($rules['phone'])) {
            $rules['phone'][] = static function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    (new PhoneCast)->set(new UserPersonalData, $attribute, $value, []);
                } catch (InvalidArgumentException) {
                    $fail('Informe um telefone válido com DDD.');
                }
            };
        }

        if ($type === AffiliationType::Student) {
            $rules['address'] = ['sometimes', 'nullable', 'array:city_id,street,number,neighborhood,zip_code'];
            if (! $partial) {
                $rules['address'][] = 'required_array_keys:city_id,street,number,neighborhood,zip_code';
            }
            foreach ($this->addressRules() as $field => $fieldRules) {
                $presence = $partial ? ['sometimes'] : ['exclude_if:address,null', 'required_with:address'];
                $rules['address.'.$field] = [...$presence, ...array_values(array_filter($fieldRules, fn (mixed $rule): bool => $rule !== 'required'))];
            }
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeOwnPersonalData(array $data): array
    {
        foreach ($data as $field => $value) {
            if (is_string($value)) {
                $data[$field] = trim($value) === '' ? null : trim($value);
            }
        }
        if (is_array($data['address'] ?? null)) {
            $data['address'] = array_map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $data['address']);
            if (array_key_exists('zip_code', $data['address'])) {
                $data['address']['zip_code'] = BrazilianAddressHelper::cepForValidation($data['address']['zip_code']);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $extraAllowed
     */
    protected function validateOwnPersonalDataFields(Validator $validator, array $data, AffiliationType $type, array $extraAllowed = []): void
    {
        $allowed = [...$this->ownPersonalDataFields($type), ...$extraAllowed];
        if ($type === AffiliationType::Student) {
            $allowed[] = 'address';
        }
        foreach (array_diff(array_keys($data), $allowed) as $field) {
            $validator->errors()->add((string) $field, 'O vínculo selecionado não permite alterar este campo.');
        }
    }

    /** @return array<string, string> */
    protected function ownPersonalDataAttributes(): array
    {
        return [
            'rg' => 'RG', 'rg_issuer' => 'órgão emissor', 'rg_issue_date' => 'data de emissão do RG',
            'birth_date' => 'data de nascimento', 'phone' => 'telefone', 'job_role' => 'cargo/função que atua',
            'training_course' => 'curso de formação', 'education_level' => 'nível', 'experience_time' => 'tempo de experiência na função',
            'address' => 'endereço', 'address.city_id' => 'cidade', 'address.street' => 'logradouro',
            'address.number' => 'número', 'address.neighborhood' => 'bairro', 'address.zip_code' => 'CEP',
        ];
    }
}
