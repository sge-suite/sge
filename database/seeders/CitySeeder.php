<?php

namespace Database\Seeders;

use App\Concerns\CityValidationRules;
use App\Models\City;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class CitySeeder extends Seeder
{
    use CityValidationRules;

    private const int BATCH_SIZE = 500;

    /**
     * Seed the local Brazilian cities catalog.
     */
    public function run(): void
    {
        $cities = $this->readCatalog();
        DB::transaction(function () use ($cities): void {
            foreach (array_chunk($cities, self::BATCH_SIZE) as $batch) {
                $existing = City::query()
                    ->whereIn('ibge_code', array_column($batch, 'ibge_code'))
                    ->get()
                    ->keyBy('ibge_code');

                foreach ($batch as $city) {
                    $model = $existing->get($city['ibge_code']) ?? new City;

                    if ($model->exists && $model->name === $city['name'] && $model->state->value === $city['state']) {
                        continue;
                    }

                    $model->fill($city);
                    $model->save();
                }
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

            $validator = Validator::make([
                'ibge_code' => $ibgeCode,
                'name' => is_string($name) ? trim($name) : $name,
                'state' => $state,
            ], $this->cityRules(), [
                'ibge_code.*' => 'O catálogo contém um código IBGE inválido.',
                'name.required' => 'O catálogo contém uma cidade sem nome válido.',
                'name.string' => 'O catálogo contém uma cidade sem nome válido.',
                'name.max' => 'O catálogo contém uma cidade que excede o limite de 120 caracteres.',
                'state.*' => 'O catálogo contém uma UF inválida.',
            ]);

            if ($validator->fails()) {
                throw new InvalidArgumentException($validator->errors()->first());
            }

            /** @var array{ibge_code: string, name: string, state: string} $validated */
            $validated = $validator->validated();
            $ibgeCode = $validated['ibge_code'];

            if (isset($ibgeCodes[$ibgeCode])) {
                throw new InvalidArgumentException("O código IBGE {$ibgeCode} está duplicado no catálogo.");
            }

            $ibgeCodes[$ibgeCode] = true;

            $cities[] = $validated;
        }

        if ($cities === []) {
            throw new InvalidArgumentException('O catálogo de cidades não pode estar vazio.');
        }

        return $cities;
    }
}
