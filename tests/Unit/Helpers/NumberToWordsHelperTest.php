<?php

use App\Helpers\NumberToWordsHelper;
use Brick\Math\BigDecimal;
use Tests\TestCase;

uses(TestCase::class);

test('writes non-negative cardinal numbers in Portuguese', function (int $value, string $expected) {
    expect(NumberToWordsHelper::cardinal($value))->toBe($expected);
})->with([
    'zero' => [0, 'zero'],
    'hundreds' => [120, 'cento e vinte'],
    'thousands' => [1_200, 'mil e duzentos'],
]);

test('writes zero reais', function () {
    expect(NumberToWordsHelper::brl('0'))->toBe('zero reais');
});

test('writes a singular real', function () {
    expect(NumberToWordsHelper::brl('1.00'))->toBe('um real');
});

test('writes a singular centavo without zero reais', function () {
    expect(NumberToWordsHelper::brl('0.01'))->toBe('um centavo');
});

test('writes reais without centavos', function () {
    expect(NumberToWordsHelper::brl('1200.00'))->toBe('mil e duzentos reais');
});

test('writes reais and centavos', function () {
    expect(NumberToWordsHelper::brl('1200.50'))->toBe('mil e duzentos reais e cinquenta centavos');
});

test('pluralizes reais and centavos', function () {
    expect(NumberToWordsHelper::brl('2.02'))->toBe('dois reais e dois centavos');
});

test('preserves trailing-zero centavos from decimal input', function () {
    expect(NumberToWordsHelper::brl('1.10'))->toBe('um real e dez centavos');
});

test('accepts decimal objects', function () {
    expect(NumberToWordsHelper::brl(BigDecimal::of('42.25')))->toBe('quarenta e dois reais e vinte e cinco centavos');
});

test('global helpers delegate to NumberToWordsHelper', function () {
    expect(numberToWords(120))->toBe('cento e vinte')
        ->and(brlToWords('1.10'))->toBe('um real e dez centavos');
});

test('rejects negative values', function () {
    expect(fn () => NumberToWordsHelper::cardinal(-1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => NumberToWordsHelper::brl('-0.01'))->toThrow(InvalidArgumentException::class);
});

test('rejects values with fractions smaller than a centavo', function () {
    expect(fn () => NumberToWordsHelper::brl('1.001'))->toThrow(InvalidArgumentException::class);
});
