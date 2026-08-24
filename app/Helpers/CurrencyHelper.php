<?php

declare(strict_types=1);

namespace App\Helpers;

use Illuminate\Support\Number;

final class CurrencyHelper
{
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
