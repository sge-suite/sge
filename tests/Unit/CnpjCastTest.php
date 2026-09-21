<?php

use App\Casts\CnpjCast;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Tests\TestCase;

uses(TestCase::class);

test('stores a valid cnpj without its mask', function () {
    $cast = new CnpjCast;
    $model = new class extends Model {};

    $cnpj = $cast->set($model, 'cnpj', '04.252.011/0001-10', []);

    expect($cnpj)->toBe('04252011000110')
        ->and($cast->get($model, 'cnpj', $cnpj, []))->toBe('04252011000110');
});

test('stores blank cnpjs as null', function () {
    $cast = new CnpjCast;
    $model = new class extends Model {};

    expect($cast->set($model, 'cnpj', '   ', []))->toBeNull();
});

test('rejects an invalid cnpj', function () {
    $cast = new CnpjCast;
    $model = new class extends Model {};

    expect(fn () => $cast->set($model, 'cnpj', '04.252.011/0001-11', []))
        ->toThrow(InvalidArgumentException::class);
});
