<?php

namespace App\Helpers {

    use Illuminate\Support\Number;

    class CurrencyHelper
    {
        /**
         * Formata um valor numérico para moeda (padrão BRL).
         */
        public static function format(int|float|null $value, string $currency = 'BRL', ?string $locale = 'pt_BR'): string
        {
            if (is_null($value)) {
                $value = 0;
            }

            return Number::currency($value, in: $currency, locale: $locale);
        }
    }
}

namespace {

    use App\Helpers\CurrencyHelper;

    if (! function_exists('formatCurrency')) {
        /**
         * Helper global para formatar moedas.
         */
        function formatCurrency(int|float|null $value, string $currency = 'BRL', ?string $locale = 'pt_BR'): string
        {
            return CurrencyHelper::format($value, $currency, $locale);
        }
    }
}
