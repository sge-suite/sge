<?php

use App\Casts\PhoneCast;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

uses(TestCase::class);

test('stores a valid Brazilian telephone without its mask', function () {
    $cast = new PhoneCast;
    $model = new class extends Model {};

    $phone = $cast->set($model, 'phone', '(55) 99999-9999', []);

    expect($phone)->toBe('55999999999')
        ->and($cast->get($model, 'phone', $phone, []))->toBe('55999999999');
});

test('stores a fixed-line telephone without its mask', function () {
    $cast = new PhoneCast;
    $model = new class extends Model {};

    expect($cast->set($model, 'phone', '(55) 9999-9999', []))->toBe('5599999999');
});

test('stores blank telephones as null', function () {
    $cast = new PhoneCast;
    $model = new class extends Model {};

    expect($cast->set($model, 'phone', '   ', []))->toBeNull();
});

test('rejects a telephone without a Brazilian DDD mask', function () {
    $cast = new PhoneCast;
    $model = new class extends Model {};

    expect(fn () => $cast->set($model, 'phone', '55999999999', []))
        ->toThrow(InvalidArgumentException::class);
});
