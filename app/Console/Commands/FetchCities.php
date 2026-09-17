<?php

namespace App\Console\Commands;

use App\Concerns\CityValidationRules;
use App\Enums\BrazilianState;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Signature('cities:fetch
    {--force : Substitui um catálogo já existente.}
    {--output= : Caminho relativo ou absoluto do arquivo JSON de saída.}')]
#[Description('Baixa os municípios da BrasilAPI e gera o catálogo local de cidades.')]
class FetchCities extends Command
{
    use CityValidationRules;

    /**
     * Executa a coleta e grava o catálogo local.
     */
    public function handle(): int
    {
        $outputPath = $this->outputPath();

        if (File::exists($outputPath) && ! $this->option('force')) {
            $this->error("O arquivo {$outputPath} já existe. Use --force para substituí-lo.");

            return self::FAILURE;
        }

        try {
            $states = $this->fetchStates();
            $cities = $this->fetchCities($states);
            $this->writeCatalog($outputPath, $cities);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Catálogo criado em %s com %d cidades de %d UFs.',
            $outputPath,
            count($cities),
            count($states),
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function fetchStates(): array
    {
        $payload = $this->request()
            ->get('/ibge/uf/v1')
            ->throw()
            ->json();

        if (! is_array($payload)) {
            throw new RuntimeException('A resposta de UFs não é uma lista JSON.');
        }

        $states = [];

        foreach ($payload as $state) {
            if (! is_array($state)) {
                throw new RuntimeException('A resposta de UFs contém um item inválido.');
            }

            $acronym = $state['sigla'] ?? $state['acronym'] ?? null;

            if (! is_string($acronym)) {
                throw new RuntimeException('A resposta de UFs não contém uma sigla válida.');
            }

            $acronym = strtoupper(trim($acronym));

            if (BrazilianState::tryFrom($acronym) === null) {
                throw new RuntimeException("A resposta contém a UF desconhecida {$acronym}.");
            }

            $states[] = $acronym;
        }

        $expectedStates = BrazilianState::values();
        $sortedStates = $states;
        $sortedExpectedStates = $expectedStates;
        sort($sortedStates);
        sort($sortedExpectedStates);

        if ($sortedStates !== $sortedExpectedStates) {
            throw new RuntimeException('A lista de UFs retornada pela BrasilAPI não corresponde às 27 UFs esperadas.');
        }

        return array_values($expectedStates);
    }

    /**
     * @param  list<string>  $states
     * @return list<array{ibge_code: string, name: string, state: string}>
     */
    private function fetchCities(array $states): array
    {
        $responses = Http::pool(function (Pool $pool) use ($states): array {
            $requests = [];

            foreach ($states as $state) {
                $requests[$state] = $this->configureRequest($pool->as($state))
                    ->get('/ibge/municipios/v1/'.$state);
            }

            return $requests;
        }, 4);

        $cities = [];
        $codes = [];

        foreach ($states as $state) {
            $response = $responses[$state] ?? null;

            if ($response instanceof Throwable) {
                throw new RuntimeException(
                    "Falha ao consultar municípios de {$state}: {$response->getMessage()}",
                    previous: $response,
                );
            }

            if (! $response instanceof Response) {
                throw new RuntimeException("A resposta de municípios de {$state} é inválida.");
            }

            $payload = $response->throw()->json();

            if (! is_array($payload)) {
                throw new RuntimeException("A resposta de municípios de {$state} não é uma lista JSON.");
            }

            foreach ($payload as $city) {
                if (! is_array($city)) {
                    throw new RuntimeException("A resposta de municípios de {$state} contém um item inválido.");
                }

                $code = $city['codigo_ibge'] ?? $city['code'] ?? null;
                $name = $city['nome'] ?? $city['name'] ?? null;

                if (! is_string($code) && ! is_int($code)) {
                    throw new RuntimeException("A resposta de municípios de {$state} não contém código IBGE.");
                }

                if (! is_string($name)) {
                    throw new RuntimeException("A resposta de municípios de {$state} não contém nome.");
                }

                $code = trim((string) $code);
                $record = [
                    'ibge_code' => $code,
                    'name' => $this->normalizeCityName($name),
                    'state' => $state,
                ];
                $validator = Validator::make($record, $this->cityRules(), [
                    'ibge_code.*' => "O código IBGE {$code} da UF {$state} não possui sete dígitos.",
                    'name.required' => "A resposta de municípios de {$state} não contém nome.",
                    'name.max' => "O nome do município {$code} excede o limite de 120 caracteres.",
                ]);

                if ($validator->fails()) {
                    throw new RuntimeException($validator->errors()->first());
                }

                if (array_key_exists($code, $codes)) {
                    throw new RuntimeException("O código IBGE {$code} apareceu mais de uma vez.");
                }

                $codes[$code] = true;
                $cities[] = $record;
            }
        }

        usort($cities, static function (array $first, array $second) use ($states): int {
            $stateOrder = array_flip($states);
            $stateComparison = $stateOrder[$first['state']] <=> $stateOrder[$second['state']];

            return $stateComparison !== 0
                ? $stateComparison
                : Str::lower($first['name']) <=> Str::lower($second['name']);
        });

        if ($cities === []) {
            throw new RuntimeException('Nenhuma cidade foi retornada pela BrasilAPI.');
        }

        return $cities;
    }

    private function normalizeCityName(string $name): string
    {
        $name = mb_convert_case(mb_strtolower(trim($name), 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

        return preg_replace_callback(
            '/\b(Da|Das|De|Do|Dos|E)\b/u',
            static fn (array $matches): string => mb_strtolower($matches[1], 'UTF-8'),
            $name,
        ) ?? $name;
    }

    private function outputPath(): string
    {
        $option = $this->option('output');

        if (! is_string($option) || trim($option) === '') {
            return database_path('data/cities.json');
        }

        return Str::startsWith($option, DIRECTORY_SEPARATOR)
            ? $option
            : base_path($option);
    }

    /**
     * @param  list<array{ibge_code: string, name: string, state: string}>  $cities
     */
    private function writeCatalog(string $outputPath, array $cities): void
    {
        $outputDirectory = dirname($outputPath);
        File::ensureDirectoryExists($outputDirectory);

        $temporaryPath = tempnam($outputDirectory, 'cities-');

        if ($temporaryPath === false) {
            throw new RuntimeException('Não foi possível criar o arquivo temporário do catálogo.');
        }

        try {
            $json = json_encode($cities, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;

            if (File::put($temporaryPath, $json) === false || ! rename($temporaryPath, $outputPath)) {
                throw new RuntimeException("Não foi possível gravar o catálogo em {$outputPath}.");
            }

            $temporaryPath = null;
        } finally {
            if ($temporaryPath !== null && File::exists($temporaryPath)) {
                File::delete($temporaryPath);
            }
        }
    }

    private function request(): PendingRequest
    {
        return $this->configureRequest(Http::baseUrl($this->baseUrl()));
    }

    private function configureRequest(PendingRequest $request): PendingRequest
    {
        return $request
            ->baseUrl($this->baseUrl())
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
