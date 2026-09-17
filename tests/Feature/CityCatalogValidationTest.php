<?php

use App\Models\City;
use Database\Seeders\CitySeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
});

test('rejects invalid city catalog fields before writing any city', function (string $field, mixed $value) {
    $existing = City::factory()->create();
    $original = $existing->fresh()->getRawOriginal();
    $valid = ['ibge_code' => '0000001', 'name' => 'Cidade de Teste', 'state' => 'RS'];
    $invalid = [...$valid, 'ibge_code' => '0000002', $field => $value];

    File::partialMock()->shouldReceive('get')
        ->once()->with(database_path('data/cities.json'))
        ->andReturn(json_encode([$valid, $invalid], JSON_THROW_ON_ERROR));

    expect(fn () => $this->seed(CitySeeder::class))->toThrow(InvalidArgumentException::class)
        ->and(City::count())->toBe(1)
        ->and($existing->fresh()->getRawOriginal())->toBe($original);

    Http::assertNothingSent();
})->with([
    'missing code' => ['ibge_code', null],
    'numeric code' => ['ibge_code', 1000001],
    'short code' => ['ibge_code', '000001'],
    'long code' => ['ibge_code', '00000001'],
    'code containing letters' => ['ibge_code', '000000a'],
    'code containing whitespace' => ['ibge_code', '0000001 '],
    'missing name' => ['name', null],
    'blank name' => ['name', '   '],
    'numeric name' => ['name', 123],
    'long name' => ['name', str_repeat('á', 121)],
    'missing state' => ['state', null],
    'unknown state' => ['state', 'XX'],
    'lowercase catalog state' => ['state', 'rs'],
    'state containing whitespace' => ['state', ' RS '],
]);

test('accepts city catalog character limits and preserves leading IBGE zeros', function () {
    File::partialMock()->shouldReceive('get')
        ->once()->with(database_path('data/cities.json'))
        ->andReturn(json_encode([
            ['ibge_code' => '0000001', 'name' => '  '.str_repeat('á', 120).'  ', 'state' => 'RS'],
        ], JSON_THROW_ON_ERROR));

    $this->seed(CitySeeder::class);

    expect(City::query()->sole()->ibge_code)->toBe('0000001')
        ->and(City::query()->sole()->name)->toBe(str_repeat('á', 120));
    Http::assertNothingSent();
});

test('keeps rejecting duplicate IBGE codes in the catalog', function () {
    $city = ['ibge_code' => '0000001', 'name' => 'Cidade de Teste', 'state' => 'RS'];
    File::partialMock()->shouldReceive('get')
        ->once()->with(database_path('data/cities.json'))
        ->andReturn(json_encode([$city, [...$city, 'name' => 'Outra Cidade']], JSON_THROW_ON_ERROR));

    expect(fn () => $this->seed(CitySeeder::class))->toThrow(InvalidArgumentException::class)
        ->and(City::count())->toBe(0);
});

test('applies city validation rules when saving the model', function (string $field, mixed $value) {
    expect(fn () => City::query()->create([
        'ibge_code' => '0000001',
        'name' => 'Cidade de Teste',
        'state' => 'RS',
        $field => $value,
    ]))->toThrow(ValidationException::class)
        ->and(City::count())->toBe(0);
})->with([
    'invalid code' => ['ibge_code', '000001'],
    'blank name' => ['name', '   '],
    'long name' => ['name', str_repeat('á', 121)],
]);
