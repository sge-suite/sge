<?php

namespace App\Support;

use App\Casts\PhoneCast;
use App\Concerns\GrantingPartyValidationRules;
use App\Enums\BrazilianState;
use App\Helpers\BrazilianAddressHelper;
use App\Helpers\BrazilianDocumentHelper;
use App\Helpers\DigitsHelper;
use App\Models\City;
use App\Models\GrantingParty;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GrantingPartyImportValidator
{
    use GrantingPartyValidationRules;

    /** @var array<string, array<string, list<int>>> */
    private array $cities = [];

    /**
     * @param  array<string, string>  $values
     * @return array{payload: array<string, mixed>, errors: array<string, list<string>>}
     */
    public function validate(array $values): array
    {
        $payload = [];
        foreach (GrantingPartyCsv::FIELDS as $header => $attribute) {
            if (! in_array($header, ['cidade', 'uf'], true)) {
                Arr::set($payload, $attribute, trim($values[$header] ?? ''));
            }
        }
        $payload['document_type'] = Str::lower($payload['document_type']);
        $state = Str::upper(trim($values['uf'] ?? ''));
        $cityName = trim($values['cidade'] ?? '');
        $place = Validator::make(['uf' => $state, 'cidade' => $cityName], [
            'uf' => ['required', Rule::in(BrazilianState::values())], 'cidade' => ['required', 'string', 'max:120'],
        ]);
        $errors = array_map(fn (array $messages): array => array_values($messages), $place->errors()->toArray());
        $cityIds = $place->fails() ? [] : $this->cityIds($state, $cityName);
        if (! $place->fails() && count($cityIds) !== 1) {
            $errors['cidade'] = [count($cityIds) === 0
                ? 'Cidade não encontrada nesta UF. Confira o nome no catálogo do SGE.'
                : 'O nome e a UF correspondem a mais de uma cidade. Corrija o catálogo antes de importar.'];
        }
        $payload['address']['city_id'] = count($cityIds) === 1 ? $cityIds[0] : null;
        $payload['address']['zip_code'] = BrazilianAddressHelper::cepForValidation($payload['address']['zip_code']);
        $attributes = array_flip(GrantingPartyCsv::FIELDS);
        $attributes['address.city_id'] = 'cidade';
        $validator = Validator::make($payload, $this->grantingPartyFormRules(), [], $attributes);
        $validator->after(fn ($validator) => $this->validateGrantingPartyInput($validator, $payload));
        foreach ($validator->errors()->toArray() as $field => $messages) {
            if ($field !== 'address.city_id') {
                $errors[$attributes[$field] ?? $field] = array_values($messages);
            }
        }
        $documentPattern = $payload['document_type'] === 'cnpj' ? '/^[a-zA-Z0-9.\/\s-]+$/u' : '/^[0-9.\/\s-]+$/u';
        if ($payload['document_number'] !== '' && preg_match($documentPattern, $payload['document_number']) !== 1) {
            $errors['documento'] = ['Use CPF numérico ou CNPJ numérico/alfanumérico como texto, com máscara opcional. Não use notação científica.'];
        }
        if ($errors !== []) {
            return ['payload' => $payload, 'errors' => $errors];
        }

        $payload['document_number'] = $payload['document_type'] === 'cnpj'
            ? BrazilianDocumentHelper::normalizeCnpj($payload['document_number'])
            : DigitsHelper::only($payload['document_number']);
        $payload['address']['zip_code'] = DigitsHelper::only($payload['address']['zip_code']);
        $payload['phone'] = (new PhoneCast)->set(new GrantingParty, 'phone', $payload['phone'], []);
        foreach (['email', 'professional_council', 'council_registration_number', 'credentialing_process_number'] as $field) {
            $payload[$field] = $payload[$field] === '' ? null : $payload[$field];
        }

        return ['payload' => $payload, 'errors' => []];
    }

    /** @return list<int> */
    private function cityIds(string $state, string $name): array
    {
        if (! isset($this->cities[$state])) {
            $this->cities[$state] = [];
            foreach (City::query()->forState($state)->get(['id', 'name']) as $city) {
                $this->cities[$state][$this->cityKey($city->name)][] = $city->id;
            }
        }

        return $this->cities[$state][$this->cityKey($name)] ?? [];
    }

    private function cityKey(string $name): string
    {
        return preg_replace('/\s+/u', '', Str::lower(Str::ascii($name))) ?? '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<int>  $excludedIds
     * @return list<array{id: int, name: string, address: string, inactive: bool}>
     */
    public function duplicates(array $payload, int $campusId, array $excludedIds = []): array
    {
        $matches = [];
        foreach (GrantingParty::query()->forCampus($campusId)->where('document_type', $payload['document_type'])
            ->where('document_number', $payload['document_number'])->whereNotIn('id', $excludedIds)->with('address.city')->orderBy('id')->get() as $party) {
            $matches[] = [
                'id' => $party->id, 'name' => $party->name,
                'address' => $party->address->street.', '.$party->address->number.' · '.$party->address->city->name.'/'.$party->address->city->state->value,
                'inactive' => $party->deactivated_at !== null,
            ];
        }

        return $matches;
    }
}
