<?php

use App\Casts\CpfCast;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Tests\TestCase;

uses(TestCase::class);

test('stores a valid cpf without its mask', function () {
    $cast = new CpfCast;
    $model = new class extends Model {};

    $cpf = $cast->set($model, 'cpf', '529.982.247-25', []);

    expect($cpf)->toBe('52998224725')
        ->and($cast->get($model, 'cpf', $cpf, []))->toBe('52998224725');
});

test('rejects an invalid cpf', function () {
    $cast = new CpfCast;
    $model = new class extends Model {};

    expect(fn () => $cast->set($model, 'cpf', '529.982.247-26', []))
        ->toThrow(InvalidArgumentException::class);
});
