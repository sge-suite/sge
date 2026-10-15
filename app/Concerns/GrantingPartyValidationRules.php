<?php

namespace App\Concerns;

use App\Casts\PhoneCast;
use App\Enums\PartyDocumentType;
use App\Helpers\BrazilianDocumentHelper;
use App\Helpers\DigitsHelper;
use App\Models\GrantingParty;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LaravelLegends\PtBrValidator\Rules\Cnpj;
use LaravelLegends\PtBrValidator\Rules\Cpf;

trait GrantingPartyValidationRules
{
    use AddressValidationRules;

    /** @return array<string, array<int, mixed>> */
    protected function grantingPartyRules(): array
    {
        return [
            'document_type' => ['required', Rule::in(PartyDocumentType::values())],
            'document_number' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'representative_name' => ['required', 'string', 'max:255'],
            'representative_role' => ['required', 'string', 'max:255'],
            'field_of_activity' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'email', 'max:254'],
            'professional_council' => ['nullable', 'string', 'max:120'],
            'council_registration_number' => ['nullable', 'string', 'max:64'],
            'credentialing_process_number' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    protected function grantingPartyFormRules(): array
    {
        $rules = $this->grantingPartyRules();
        $rules['address'] = ['required', 'array:city_id,street,number,neighborhood,zip_code'];
        foreach ($this->addressRules() as $field => $fieldRules) {
            $rules['address.'.$field] = $fieldRules;
        }

        return $rules;
    }

    /** @param array<string, mixed> $data */
    protected function validateGrantingPartyInput(Validator $validator, array $data): void
    {
        $type = is_string($data['document_type'] ?? null) ? PartyDocumentType::tryFrom($data['document_type']) : null;
        if ($type !== null && is_string($data['document_number'] ?? null)) {
            $rule = $type === PartyDocumentType::CPF ? new Cpf : new Cnpj;
            $document = $type === PartyDocumentType::CPF
                ? DigitsHelper::only($data['document_number'])
                : BrazilianDocumentHelper::normalizeCnpj($data['document_number']);
            if (($type === PartyDocumentType::CNPJ && preg_match('/^[A-Z0-9]{12}[0-9]{2}$/', $document) !== 1)
                || ! $rule->passes('document_number', $document)) {
                $validator->errors()->add('document_number', 'Informe um '.$type->label().' válido.');
            }
        }
        if (filled($data['phone'] ?? null) && is_string($data['phone'])) {
            try {
                (new PhoneCast)->set(new GrantingParty, 'phone', $data['phone'], []);
            } catch (\InvalidArgumentException) {
                $validator->errors()->add('phone', 'Informe um telefone válido com DDD.');
            }
        }
    }

    /** @param array<string, mixed> $data
     * @param  list<string>  $allowed
     */
    protected function validateGrantingPartyFields(Validator $validator, array $data, array $allowed): void
    {
        foreach (array_diff(array_keys($data), $allowed) as $field) {
            $validator->errors()->add((string) $field, 'O formulário contém um campo não permitido.');
        }
    }
}
