<?php

use App\Enums\BrazilianState;
use App\Models\City;
use Database\Seeders\CitySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

test('creates the cities table with the catalog schema and indexes', function () {
    expect(Schema::hasTable('cities'))->toBeTrue()
        ->and(Schema::hasColumns('cities', [
            'id',
            'ibge_code',
            'name',
            'state',
            'created_at',
            'updated_at',
        ]))->toBeTrue();

    $indexes = Schema::getIndexes('cities');

    expect(collect($indexes)->firstWhere('name', 'cities_ibge_code_unique'))->toMatchArray([
        'name' => 'cities_ibge_code_unique',
        'columns' => ['ibge_code'],
        'unique' => true,
        'primary' => false,
    ])
        ->and(collect($indexes)->firstWhere('name', 'cities_state_index'))->toMatchArray([
            'name' => 'cities_state_index',
            'columns' => ['state'],
            'unique' => false,
            'primary' => false,
        ])
        ->and(collect($indexes)->firstWhere('name', 'cities_state_name_index'))->toMatchArray([
            'name' => 'cities_state_name_index',
            'columns' => ['state', 'name'],
            'unique' => false,
            'primary' => false,
        ]);
});

test('seeds the local catalog without making HTTP requests and is idempotent', function () {
    Http::preventStrayRequests();
    Http::fake();

    $this->seed(CitySeeder::class);

    expect(City::count())->toBe(5571)
        ->and(City::query()->where('ibge_code', '4300109')->first())
        ->name->toBe('Agudo');

    $this->seed(CitySeeder::class);

    expect(City::count())->toBe(5571);
    Http::assertNothingSent();
});

test('registers the city seeder in the main database seeder', function () {
    $this->seed();

    expect(City::count())->toBe(5571);
});

test('casts the state to BrazilianState', function () {
    $city = City::query()->create([
        'ibge_code' => '9999999',
        'name' => 'Cidade de Teste',
        'state' => BrazilianState::RioGrandeDoSul,
    ]);

    expect($city->state)->toBe(BrazilianState::RioGrandeDoSul);
});

test('enforces unique IBGE codes', function () {
    City::query()->create([
        'ibge_code' => '9999999',
        'name' => 'Cidade de Teste',
        'state' => BrazilianState::RioGrandeDoSul,
    ]);

    expect(fn () => City::query()->create([
        'ibge_code' => '9999999',
        'name' => 'Outra Cidade',
        'state' => BrazilianState::SaoPaulo,
    ]))->toThrow(QueryException::class);
});

test('filters cities by state and name', function () {
    $this->seed(CitySeeder::class);

    $cities = City::query()
        ->forState(BrazilianState::RioGrandeDoSul)
        ->whereLike('name', '%agudo%')
        ->get();

    expect($cities)->toHaveCount(1)
        ->and($cities->first()->name)->toBe('Agudo')
        ->and($cities->first()->state)->toBe(BrazilianState::RioGrandeDoSul);
});
