<?php

use App\Enums\BrazilianState;
use App\Enums\HolidayScope;
use App\Models\City;
use App\Models\Holiday;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

test('creates the holidays table with catalog indexes', function () {
    expect(Schema::hasTable('holidays'))->toBeTrue()
        ->and(Schema::hasColumns('holidays', [
            'id',
            'date',
            'name',
            'scope',
            'state_code',
            'city_id',
            'deleted_at',
            'created_at',
            'updated_at',
        ]))->toBeTrue();

    $indexes = collect(Schema::getIndexes('holidays'));

    expect($indexes->firstWhere('name', 'holidays_date_index'))->not->toBeNull()
        ->and($indexes->firstWhere('name', 'holidays_scope_index'))->not->toBeNull()
        ->and($indexes->firstWhere('name', 'holidays_state_code_date_index'))->not->toBeNull()
        ->and($indexes->firstWhere('name', 'holidays_city_id_date_index'))->not->toBeNull()
        ->and($indexes->firstWhere('name', 'holidays_date_name_scope_state_code_city_id_deleted_at_unique'))->not->toBeNull();
});

test('casts date, scope and state and relates municipal holidays to cities', function () {
    $city = createTestCity(BrazilianState::RioGrandeDoSul);
    $holiday = createHoliday([
        'scope' => HolidayScope::Municipal,
        'city_id' => $city->id,
    ]);

    expect($holiday->date)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($holiday->scope)->toBe(HolidayScope::Municipal)
        ->and($holiday->state_code)->toBe(BrazilianState::RioGrandeDoSul)
        ->and($holiday->city->is($city))->toBeTrue()
        ->and($city->holidays->sole()->is($holiday))->toBeTrue();
});

test('validates location fields according to the holiday scope', function () {
    $city = createTestCity(BrazilianState::RioGrandeDoSul);

    expect(fn () => createHoliday([
        'scope' => HolidayScope::National,
        'state_code' => BrazilianState::SaoPaulo,
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => createHoliday([
        'scope' => HolidayScope::National,
        'city_id' => $city->id,
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => createHoliday([
        'scope' => HolidayScope::State,
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => createHoliday([
        'scope' => HolidayScope::State,
        'state_code' => BrazilianState::RioGrandeDoSul,
        'city_id' => $city->id,
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => createHoliday([
        'scope' => HolidayScope::Municipal,
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => createHoliday([
        'scope' => HolidayScope::Municipal,
        'city_id' => $city->id,
        'state_code' => BrazilianState::SaoPaulo,
    ]))->toThrow(InvalidArgumentException::class);
});

test('allows distinct scopes on the same date and enforces unique active holidays', function () {
    $city = createTestCity(BrazilianState::RioGrandeDoSul);
    $date = '2026-04-21';
    $name = 'Feriado de teste';

    createHoliday(['date' => $date, 'name' => $name]);
    createHoliday([
        'date' => $date,
        'name' => $name,
        'scope' => HolidayScope::State,
        'state_code' => BrazilianState::RioGrandeDoSul,
    ]);
    createHoliday([
        'date' => $date,
        'name' => $name,
        'scope' => HolidayScope::Municipal,
        'city_id' => $city->id,
        'state_code' => BrazilianState::RioGrandeDoSul,
    ]);

    expect(Holiday::count())->toBe(3)
        ->and(fn () => createHoliday(['date' => $date, 'name' => $name]))->toThrow(QueryException::class)
        ->and(fn () => createHoliday([
            'date' => $date,
            'name' => $name,
            'scope' => HolidayScope::State,
            'state_code' => BrazilianState::RioGrandeDoSul,
        ]))->toThrow(QueryException::class)
        ->and(fn () => createHoliday([
            'date' => $date,
            'name' => $name,
            'scope' => HolidayScope::Municipal,
            'city_id' => $city->id,
            'state_code' => BrazilianState::RioGrandeDoSul,
        ]))->toThrow(QueryException::class);
});

test('soft deletes holidays and filters them by UF', function () {
    $deletedHoliday = createHoliday([
        'date' => '2026-04-21',
        'name' => 'Feriado de teste',
    ]);
    $deletedHoliday->delete();

    createHoliday([
        'date' => '2026-09-20',
        'scope' => HolidayScope::State,
        'state_code' => BrazilianState::RioGrandeDoSul,
    ]);
    createHoliday([
        'date' => '2026-09-20',
        'scope' => HolidayScope::State,
        'state_code' => BrazilianState::SaoPaulo,
    ]);

    $holidays = Holiday::query()->forState('rs')->get();

    expect($holidays)->toHaveCount(1)
        ->and($holidays->sole()->state_code)->toBe(BrazilianState::RioGrandeDoSul)
        ->and(Holiday::count())->toBe(2)
        ->and(Holiday::withTrashed()->count())->toBe(3);
});

test('imports national and state holidays from BrasilAPI without overwriting active records', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        return match ($request->url()) {
            'https://brasilapi.com.br/api/feriados/v1/2026' => Http::response([
                ['date' => '2026-01-01', 'name' => 'Confraternização Mundial', 'type' => 'national'],
                ['date' => '2026-04-21', 'name' => 'Tiradentes', 'type' => 'national'],
                ['date' => '2026-10-28', 'name' => 'Ponto facultativo', 'type' => 'national', 'optional' => true],
            ]),
            'https://brasilapi.com.br/api/feriados/v1/2026?uf=RS' => Http::response([
                ['date' => '2026-01-01', 'name' => 'Confraternização Mundial', 'type' => 'national'],
                ['date' => '2026-09-20', 'name' => 'Revolução Farroupilha', 'type' => 'state'],
            ]),
        };
    });

    $this->artisan('holidays:import', ['year' => '2026'])
        ->assertSuccessful();

    $tiradentes = Holiday::query()->where('name', 'Tiradentes')->sole();

    expect(Holiday::count())->toBe(2)
        ->and($tiradentes->scope)->toBe(HolidayScope::National)
        ->and($tiradentes->created_at)->not->toBeNull();

    $this->artisan('holidays:import', ['year' => '2026', '--uf' => 'rs'])
        ->assertSuccessful();

    $stateHoliday = Holiday::query()->where('name', 'Revolução Farroupilha')->sole();

    expect(Holiday::count())->toBe(3)
        ->and($stateHoliday->scope)->toBe(HolidayScope::State)
        ->and($stateHoliday->state_code)->toBe(BrazilianState::RioGrandeDoSul);

    $this->artisan('holidays:import', ['year' => '2026', '--uf' => 'RS'])
        ->assertSuccessful();

    expect(Holiday::count())->toBe(3);
    Http::assertSentCount(3);
});

function createHoliday(array $attributes = []): Holiday
{
    return Holiday::query()->create([
        'date' => '2026-01-01',
        'name' => 'Feriado nacional de teste',
        'scope' => HolidayScope::National,
        ...$attributes,
    ]);
}

test('validates required holiday fields when saving the model', function (string $field, mixed $value) {
    expect(fn () => createHoliday([$field => $value]))->toThrow(InvalidArgumentException::class)
        ->and(Holiday::count())->toBe(0);
})->with([
    'missing date' => ['date', null],
    'missing name' => ['name', null],
    'blank name' => ['name', '   '],
    'numeric name' => ['name', 123],
    'long name' => ['name', str_repeat('á', 256)],
    'missing scope' => ['scope', null],
    'invalid city identifier' => ['city_id', 'abc'],
]);

test('rejects invalid holiday updates without changing persisted data', function () {
    $holiday = createHoliday();
    $original = $holiday->fresh()->getRawOriginal();

    expect(fn () => $holiday->update(['name' => '']))->toThrow(InvalidArgumentException::class)
        ->and($holiday->fresh()->getRawOriginal())->toBe($original);
});

test('accepts the holiday name limit including multibyte characters', function () {
    $holiday = createHoliday(['name' => str_repeat('á', 255)]);
    expect($holiday->fresh()->name)->toBe(str_repeat('á', 255));
});

test('rejects malformed imported holidays before writing any rows', function (string $field, mixed $value) {
    Http::preventStrayRequests();
    $existing = createHoliday(['name' => 'Registro existente']);
    $original = $existing->fresh()->getRawOriginal();
    $valid = ['date' => '2026-04-21', 'name' => 'Tiradentes', 'type' => 'national'];
    Http::fake([
        'https://brasilapi.com.br/api/feriados/v1/2026' => Http::response([
            $valid,
            [...$valid, 'name' => 'Outro feriado', $field => $value],
        ]),
    ]);

    $this->artisan('holidays:import', ['year' => '2026'])->assertFailed();
    expect(Holiday::count())->toBe(1)
        ->and($existing->fresh()->getRawOriginal())->toBe($original);
    Http::assertSentCount(1);
})->with([
    'missing date' => ['date', null],
    'non-string date' => ['date', 20260421],
    'impossible date' => ['date', '2026-02-30'],
    'unformatted date' => ['date', '21/04/2026'],
    'date containing time' => ['date', '2026-04-21 00:00:00'],
    'date from another year' => ['date', '2025-04-21'],
    'missing name' => ['name', null],
    'blank name' => ['name', '   '],
    'non-string name' => ['name', 123],
    'long name' => ['name', str_repeat('á', 256)],
    'unknown scope' => ['type', 'unknown'],
    'state scope without requested UF' => ['type', 'state'],
]);

function createTestCity(BrazilianState $state): City
{
    return City::query()->create([
        'ibge_code' => (string) fake()->unique()->numberBetween(1000000, 9999999),
        'name' => fake()->city(),
        'state' => $state,
    ]);
}
