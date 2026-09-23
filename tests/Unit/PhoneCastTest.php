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

test('accepts Brazilian fixed and mobile telephones with digits only', function (string $input, string $expected) {
    $cast = new PhoneCast;
    $model = new class extends Model {};

    expect($cast->set($model, 'phone', $input, []))->toBe($expected);
})->with([
    'fixed' => ['5599999999', '5599999999'],
    'CNPJ lookup number' => ['5599531809', '5599531809'],
    'mobile' => ['55999999999', '55999999999'],
]);

test('rejects invalid Brazilian telephones with or without a mask', function (string $input) {
    $cast = new PhoneCast;
    $model = new class extends Model {};

    expect(fn () => $cast->set($model, 'phone', $input, []))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'too short' => '559999999',
    'too long' => '559999999999',
    'masked without DDD' => '99999-9999',
    'letters with digits' => '55a999999999',
]);
