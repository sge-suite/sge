<?php

namespace App\Support;

use App\Enums\BrazilianState;
use App\Helpers\BrazilianAddressHelper;
use App\Helpers\DigitsHelper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LaravelLegends\PtBrValidator\Rules\FormatoCep;

class BrasilApiCepLookup
{
    /** @return array{zip_code: string, street: string, neighborhood: string, city_name: string, state: string} */
    public function lookup(string $cep): array
    {
        $cep = BrazilianAddressHelper::cepForValidation($cep);
        Validator::make(['zip_code' => $cep], ['zip_code' => ['bail', 'required', 'string', new FormatoCep]])->validate();
        $cep = DigitsHelper::only($cep);

        try {
            $response = Http::baseUrl(rtrim(config('services.brasil_api.base_url'), '/'))
                ->acceptJson()->connectTimeout(3)->timeout(10)->get('cep/v1/'.$cep);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['zip_code' => 'A consulta está indisponível. Tente novamente ou preencha o endereço manualmente.']);
        }

        if ($response->notFound()) {
            throw ValidationException::withMessages(['zip_code' => 'CEP não encontrado. Confira o CEP ou preencha o endereço manualmente.']);
        }

        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ($data['cep'] ?? null) !== $cep
            || ! is_string($data['city'] ?? null) || trim($data['city']) === ''
            || ! is_string($data['state'] ?? null) || BrazilianState::tryFrom($data['state']) === null
            || ! is_string($data['street'] ?? null) || ! is_string($data['neighborhood'] ?? null)) {
            throw ValidationException::withMessages(['zip_code' => 'Não foi possível consultar este CEP. Tente novamente ou preencha o endereço manualmente.']);
        }

        $text = static fn (string $key): string => mb_substr(trim($data[$key]), 0, 255);

        return ['zip_code' => $cep, 'street' => $text('street'), 'neighborhood' => $text('neighborhood'), 'city_name' => $text('city'), 'state' => $text('state')];
    }
}
