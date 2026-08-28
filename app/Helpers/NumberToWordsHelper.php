<?php

declare(strict_types=1);

namespace App\Helpers;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Number;
use RuntimeException;

final class NumberToWordsHelper
{
    public static function cardinal(int $value): string
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('O número deve ser não negativo.');
        }

        $formatted = Number::spell($value);

        if ($formatted === false) {
            throw new RuntimeException('Não foi possível converter o número por extenso.');
        }

        return $formatted;
    }

    public static function brl(string|int|BigDecimal $value): string
    {
        $amount = self::normalizeBrl($value);

        try {
            $reais = $amount->getIntegralPart()->toInt();
            $centavos = $amount->getFractionalPart()->multipliedBy(100)->toInt();
        } catch (MathException $exception) {
            throw new \InvalidArgumentException(
                'O valor monetário excede o limite suportado para escrita por extenso.',
                previous: $exception,
            );
        }

        $parts = [];

        if ($reais > 0) {
            $parts[] = self::currencyPart($reais, 'real', 'reais');
        }

        if ($centavos > 0) {
            $parts[] = self::currencyPart($centavos, 'centavo', 'centavos');
        }

        return $parts === [] ? 'zero reais' : implode(' e ', $parts);
    }

    private static function currencyPart(int $value, string $singular, string $plural): string
    {
        return sprintf('%s %s', self::cardinal($value), $value === 1 ? $singular : $plural);
    }

    private static function normalizeBrl(string|int|BigDecimal $value): BigDecimal
    {
        try {
            $amount = BigDecimal::of($value);

            if ($amount->isNegative()) {
                throw new \InvalidArgumentException('O valor monetário deve ser não negativo.');
            }

            return $amount->toScale(2);
        } catch (MathException $exception) {
            throw new \InvalidArgumentException(
                'O valor monetário deve ser um decimal com até duas casas.',
                previous: $exception,
            );
        }
    }
}
