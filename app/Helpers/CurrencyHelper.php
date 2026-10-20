<?php

declare(strict_types=1);

namespace App\Helpers;

use Brick\Math\BigDecimal;
use Illuminate\Support\Number;

final class CurrencyHelper
{
    public static function formatDecimal(string $value): string
    {
        $amount = BigDecimal::of($value)->toScale(2);
        $digits = (string) $amount->abs();
        [$integer, $fraction] = explode('.', $digits);
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer);

        return ($amount->isNegative() ? '-' : '').'R$ '.$grouped.','.$fraction;
    }

    /**
     * Formata um valor numérico para moeda (padrão BRL).
     */
    public static function format(int|float|null $value, string $currency = 'BRL', ?string $locale = 'pt_BR'): string
    {
        if (is_null($value)) {
            $value = 0;
        }

        $formatted = Number::currency($value, in: $currency, locale: $locale);

        return $formatted !== false ? $formatted : '';
    }
}
