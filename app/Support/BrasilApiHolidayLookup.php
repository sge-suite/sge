<?php

namespace App\Support;

use App\Enums\BrazilianState;
use App\Enums\HolidayScope;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class BrasilApiHolidayLookup
{
    public function url(int $year, ?BrazilianState $state): string
    {
        return rtrim((string) config('services.brasil_api.base_url'), '/').'/feriados/v1/'.$year.($state === null ? '' : '?uf='.$state->value);
    }

    /** @return list<array<string, mixed>> */
    public function fetch(int $year, ?BrazilianState $state): array
    {
        $payload = Http::acceptJson()->connectTimeout(5)->timeout(15)->retry([250, 500], 0,
            fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && ($exception->response->serverError() || $exception->response->status() === 429))
        )->get($this->url($year, $state))->throw()->json();
        if (! is_array($payload) || ! array_is_list($payload) || $payload === []) {
            throw new RuntimeException('A BrasilAPI não retornou uma lista de feriados preenchida.');
        }
        foreach ($payload as $item) {
            if (! is_array($item)) {
                throw new RuntimeException('A resposta contém um item inválido.');
            }
            Validator::make($item, [
                'date' => ['required', 'string', 'date_format:Y-m-d'], 'name' => ['required', 'string', 'max:255'],
                'type' => ['required', 'string', 'max:100'],
            ])->validate();
            if ((int) substr($item['date'], 0, 4) !== $year || trim($item['name']) === '') {
                throw new RuntimeException('A resposta contém data fora do ano solicitado ou nome vazio.');
            }
            if (array_key_exists('optional', $item) && ! is_bool($item['optional'])) {
                throw new RuntimeException('O campo optional deve ser booleano quando informado.');
            }
            if (array_key_exists('state', $item) && (! is_string($item['state']) || BrazilianState::tryFrom($item['state']) === null || $state?->value !== $item['state'])) {
                throw new RuntimeException('A UF retornada não corresponde à consulta.');
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $item */
    public function scope(array $item, ?BrazilianState $state): ?HolidayScope
    {
        if ($item['type'] === 'national') {
            return HolidayScope::National;
        }
        if ($item['type'] === 'state' && $state !== null) {
            return HolidayScope::State;
        }
        if (in_array($item['type'], ['facultative', 'optional'], true)) {
            return isset($item['state']) && $state !== null ? HolidayScope::State : ($state === null ? HolidayScope::National : null);
        }

        return null;
    }
}
