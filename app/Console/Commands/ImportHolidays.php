<?php

namespace App\Console\Commands;

use App\Concerns\HolidayValidationRules;
use App\Enums\BrazilianState;
use App\Enums\HolidayScope;
use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

#[Signature('holidays:import
    {year : Ano entre 1900 e 2199 a ser importado.}
    {--uf= : Sigla da UF para incluir feriados estaduais.}')]
#[Description('Importa feriados nacionais e estaduais da BrasilAPI sem sobrescrever registros existentes.')]
class ImportHolidays extends Command
{
    use HolidayValidationRules;

    /**
     * Importa os feriados de um ano.
     */
    public function handle(): int
    {
        $year = $this->year();
        $state = $this->state();

        try {
            $holidays = $this->fetchHolidays($year, $state);
            [$imported, $existing] = $this->storeHolidays($holidays);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Importação de %d%s concluída: %d novos e %d já existentes.',
            $year,
            $state === null ? '' : " para {$state->value}",
            $imported,
            $existing,
        ));

        return self::SUCCESS;
    }

    private function year(): int
    {
        /** @var mixed $year */
        $year = $this->argument('year');

        if (! is_string($year) || preg_match('/^\d{4}$/', $year) !== 1 || (int) $year < 1900 || (int) $year > 2199) {
            throw new RuntimeException('O ano deve ser um número entre 1900 e 2199.');
        }

        return (int) $year;
    }

    private function state(): ?BrazilianState
    {
        /** @var mixed $state */
        $state = $this->option('uf');

        if ($state === null || (is_string($state) && trim($state) === '')) {
            return null;
        }

        if (! is_string($state)) {
            throw new RuntimeException('A UF deve conter duas letras.');
        }

        $state = BrazilianState::tryFrom(strtoupper(trim($state)));

        if ($state === null) {
            throw new RuntimeException('A UF informada não é válida.');
        }

        return $state;
    }

    /**
     * @return list<array{date: CarbonImmutable, name: string, scope: HolidayScope, state: BrazilianState|null}>
     */
    private function fetchHolidays(int $year, ?BrazilianState $state): array
    {
        $payload = $this->request()
            ->get('/feriados/v1/'.$year, $state === null ? [] : ['uf' => $state->value])
            ->throw()
            ->json();

        if (! is_array($payload)) {
            throw new RuntimeException('A resposta de feriados não é uma lista JSON.');
        }

        $holidays = [];
        $keys = [];

        foreach ($payload as $holiday) {
            if (! is_array($holiday)) {
                throw new RuntimeException('A resposta de feriados contém um item inválido.');
            }

            if (($holiday['optional'] ?? false) === true) {
                continue;
            }

            $scope = match ($holiday['type'] ?? null) {
                'national' => HolidayScope::National,
                'state' => HolidayScope::State,
                default => throw new RuntimeException('A resposta contém um tipo de feriado inválido.'),
            };

            if ($scope === HolidayScope::State && $state === null) {
                throw new RuntimeException('A BrasilAPI retornou feriado estadual sem uma UF solicitada.');
            }

            $holidayState = $scope === HolidayScope::State ? $state : null;
            $name = $holiday['name'] ?? null;
            $validator = Validator::make([
                'date' => $holiday['date'] ?? null,
                'name' => is_string($name) ? trim($name) : $name,
                'scope' => $scope->value,
                'state_code' => $holidayState?->value,
                'city_id' => null,
            ], $this->holidayRules(), [
                'date.*' => 'A resposta de feriados não contém uma data válida no formato YYYY-MM-DD.',
                'name.*' => 'A resposta de feriados não contém um nome válido.',
            ]);

            if ($validator->fails()) {
                throw new RuntimeException($validator->errors()->first());
            }

            /** @var array{date: string, name: string, scope: string, state_code: string|null, city_id: int|null} $validated */
            $validated = $validator->validated();
            $date = $this->parseDate($validated['date'], $year);
            $name = $validated['name'];
            $key = $date->toDateString().'|'.$scope->value.'|'.($validated['state_code'] ?? '').'|'.$name;

            if (isset($keys[$key])) {
                throw new RuntimeException("O feriado {$name} apareceu mais de uma vez na resposta.");
            }

            $keys[$key] = true;
            $holidays[] = [
                'date' => $date,
                'name' => $name,
                'scope' => $scope,
                'state' => $holidayState,
            ];
        }

        if ($holidays === []) {
            throw new RuntimeException('Nenhum feriado foi retornado pela BrasilAPI.');
        }

        return $holidays;
    }

    private function parseDate(string $value, int $year): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        if ($date->year !== $year) {
            throw new RuntimeException("A data {$value} não pertence ao ano importado.");
        }

        return $date;
    }

    /**
     * @param  list<array{date: CarbonImmutable, name: string, scope: HolidayScope, state: BrazilianState|null}>  $holidays
     * @return array{int, int}
     */
    private function storeHolidays(array $holidays): array
    {
        $imported = 0;
        $existing = 0;
        DB::transaction(function () use ($holidays, &$imported, &$existing): void {
            foreach ($holidays as $holiday) {
                $record = Holiday::query()
                    ->firstOrCreate(
                        [
                            'date' => $holiday['date'],
                            'name' => $holiday['name'],
                            'scope' => $holiday['scope']->value,
                            'state_code' => $holiday['state']?->value,
                        ],
                    );

                if ($record->wasRecentlyCreated) {
                    $imported++;
                } else {
                    $existing++;
                }
            }
        });

        return [$imported, $existing];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->connectTimeout(10)
            ->retry([250, 500, 1000])
            ->timeout(30);
    }

    private function baseUrl(): string
    {
        return (string) config('services.brasil_api.base_url');
    }
}
