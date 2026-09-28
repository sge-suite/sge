<?php

namespace App\Support;

use App\Helpers\DigitsHelper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LaravelLegends\PtBrValidator\Rules\Cnpj;

class BrasilApiCompanyLookup
{
    /**
     * @return array{legal_name: string, trade_name: string, phone: string, address: array{street: string, number: string, neighborhood: string, zip_code: string, ibge_code: string, city_name: string, state: string}}
     */
    public function lookup(string $cnpj): array
    {
        Validator::make(['cnpj' => $cnpj], ['cnpj' => ['bail', 'required', 'string', 'max:18', new Cnpj]])->validate();
        $cnpj = DigitsHelper::only($cnpj);

        try {
            $response = Http::baseUrl(rtrim(config('services.brasil_api.base_url'), '/'))
                ->acceptJson()->connectTimeout(3)->timeout(10)
                ->get('cnpj/v1/'.$cnpj);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['cnpj' => 'A consulta está indisponível. Tente novamente ou preencha os dados manualmente.']);
        }

        if ($response->notFound()) {
            throw ValidationException::withMessages(['cnpj' => 'CNPJ não encontrado. Confira o número ou preencha os dados manualmente.']);
        }

        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ! is_string($data['razao_social'] ?? null)
            || trim($data['razao_social']) === '' || ($data['cnpj'] ?? null) !== $cnpj) {
            throw ValidationException::withMessages(['cnpj' => 'Não foi possível consultar este CNPJ. Tente novamente ou preencha os dados manualmente.']);
        }

        $text = static fn (string $key): string => is_string($data[$key] ?? null) ? mb_substr(trim($data[$key]), 0, 255) : '';
        $street = trim($text('descricao_tipo_de_logradouro').' '.$text('logradouro'));
        $ibgeCode = $data['codigo_municipio_ibge'] ?? null;

        return [
            'legal_name' => $text('razao_social'),
            'trade_name' => $text('nome_fantasia'),
            'phone' => DigitsHelper::only($text('ddd_telefone_1') ?: $text('ddd_telefone_2')),
            'address' => [
                'street' => mb_substr($street, 0, 255),
                'number' => $text('numero'),
                'neighborhood' => $text('bairro'),
                'zip_code' => $text('cep'),
                'ibge_code' => is_int($ibgeCode) || is_string($ibgeCode) ? (string) $ibgeCode : '',
                'city_name' => $text('municipio'),
                'state' => mb_strtoupper($text('uf')),
            ],
        ];
    }
}
