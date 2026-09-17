<?php

use App\Enums\BrazilianState;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

test('fetches all cities and writes a normalized catalog', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if ($request->url() === 'https://brasilapi.com.br/api/ibge/uf/v1') {
            return Http::response(array_map(
                static fn (string $state): array => ['sigla' => $state],
                BrazilianState::values(),
            ));
        }

        preg_match('/\/municipios\/v1\/([A-Z]{2})$/', $request->url(), $matches);
        $state = $matches[1] ?? null;

        if ($state === null) {
            return Http::response([], 404);
        }

        $stateIndex = array_search($state, BrazilianState::values(), true);

        if ($stateIndex === false) {
            return Http::response([], 404);
        }

        return Http::response([
            [
                'nome' => 'CIDADE DE TESTE',
                'codigo_ibge' => str_pad((string) ($stateIndex + 1), 7, '0', STR_PAD_LEFT),
            ],
        ]);
    });

    $outputPath = tempnam(sys_get_temp_dir(), 'cities-test-');

    if ($outputPath === false) {
        throw new RuntimeException('Não foi possível criar o arquivo temporário do teste.');
    }

    File::delete($outputPath);

    try {
        $this->artisan('cities:fetch', ['--output' => $outputPath])
            ->assertSuccessful()
            ->expectsOutputToContain('27 UFs');

        $catalog = json_decode(File::get($outputPath), true, 512, JSON_THROW_ON_ERROR);

        expect($catalog)->toHaveCount(27)
            ->and($catalog[0])->toBe([
                'ibge_code' => '0000001',
                'name' => 'Cidade de Teste',
                'state' => 'AC',
            ]);
    } finally {
        File::delete($outputPath);
    }
});

test('rejects invalid fetched city fields without replacing the existing catalog', function (string $field, mixed $value) {
    Http::preventStrayRequests();
    $states = BrazilianState::values();
    $responses = [
        'https://brasilapi.com.br/api/ibge/uf/v1' => Http::response(array_map(
            static fn (string $state): array => ['sigla' => $state], $states,
        )),
    ];

    foreach ($states as $index => $state) {
        $city = [
            'codigo_ibge' => str_pad((string) ($index + 1), 7, '0', STR_PAD_LEFT),
            'nome' => 'Cidade de Teste',
        ];
        if ($state === 'AC') {
            $city[$field] = $value;
        }
        $responses['https://brasilapi.com.br/api/ibge/municipios/v1/'.$state] = Http::response([$city]);
    }
    Http::fake($responses);

    $outputPath = tempnam(sys_get_temp_dir(), 'cities-test-');
    if ($outputPath === false) {
        throw new RuntimeException('Não foi possível criar o arquivo temporário do teste.');
    }
    File::put($outputPath, '[{"existing":true}]');

    try {
        $this->artisan('cities:fetch', ['--output' => $outputPath, '--force' => true])->assertFailed();
        expect(File::get($outputPath))->toBe('[{"existing":true}]');
    } finally {
        File::delete($outputPath);
    }
})->with([
    'short IBGE code' => ['codigo_ibge', '000001'],
    'invalid IBGE code' => ['codigo_ibge', '000000a'],
    'blank name' => ['nome', '   '],
    'long name' => ['nome', str_repeat('á', 121)],
]);

test('refuses to overwrite an existing catalog without force', function () {
    Http::preventStrayRequests();
    Http::fake();

    $outputPath = tempnam(sys_get_temp_dir(), 'cities-test-');

    if ($outputPath === false) {
        throw new RuntimeException('Não foi possível criar o arquivo temporário do teste.');
    }

    File::put($outputPath, '[{"existing":true}]');

    try {
        $this->artisan('cities:fetch', ['--output' => $outputPath])
            ->assertFailed()
            ->expectsOutputToContain('já existe');

        expect(File::get($outputPath))->toBe('[{"existing":true}]');
        Http::assertNothingSent();
    } finally {
        File::delete($outputPath);
    }
});
