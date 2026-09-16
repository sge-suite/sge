<?php

namespace Database\Seeders;

use App\Enums\BrazilianState;
use App\Models\City;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class CitySeeder extends Seeder
{
    private const int BATCH_SIZE = 500;

    /**
     * Seed the local Brazilian cities catalog.
     */
    public function run(): void
    {
        $cities = $this->readCatalog();
        $timestamp = now();

        DB::transaction(function () use ($cities, $timestamp): void {
            foreach (array_chunk($cities, self::BATCH_SIZE) as $batch) {
                City::query()->upsert(
                    array_map(
                        static fn (array $city): array => [
                            ...$city,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ],
                        $batch,
                    ),
                    ['ibge_code'],
                    ['name', 'state', 'updated_at'],
                );
            }
        });
    }

    /**
     * @return list<array{ibge_code: string, name: string, state: string}>
     */
    private function readCatalog(): array
    {
        $catalog = json_decode(
            File::get(database_path('data/cities.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (! is_array($catalog) || ! array_is_list($catalog)) {
            throw new InvalidArgumentException('O catálogo de cidades deve ser uma lista JSON.');
        }

        $cities = [];
        $ibgeCodes = [];

        foreach ($catalog as $city) {
            if (! is_array($city)) {
                throw new InvalidArgumentException('O catálogo de cidades contém um item inválido.');
            }

            $ibgeCode = $city['ibge_code'] ?? null;
            $name = $city['name'] ?? null;
            $state = $city['state'] ?? null;

            if (! is_string($ibgeCode) || preg_match('/^\d{7}$/', $ibgeCode) !== 1) {
                throw new InvalidArgumentException('O catálogo contém um código IBGE inválido.');
            }

            if (isset($ibgeCodes[$ibgeCode])) {
                throw new InvalidArgumentException("O código IBGE {$ibgeCode} está duplicado no catálogo.");
            }

            $ibgeCodes[$ibgeCode] = true;

            if (! is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException("A cidade {$ibgeCode} não contém um nome válido.");
            }

            if (mb_strlen(trim($name), 'UTF-8') > 120) {
                throw new InvalidArgumentException("A cidade {$ibgeCode} excede o limite de 120 caracteres.");
            }

            if (! is_string($state) || BrazilianState::tryFrom($state) === null) {
                throw new InvalidArgumentException("A cidade {$ibgeCode} contém uma UF inválida.");
            }

            $cities[] = [
                'ibge_code' => $ibgeCode,
                'name' => trim($name),
                'state' => $state,
            ];
        }

        if ($cities === []) {
            throw new InvalidArgumentException('O catálogo de cidades não pode estar vazio.');
        }

        return $cities;
    }
}
