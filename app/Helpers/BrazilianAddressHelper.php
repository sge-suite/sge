<?php

declare(strict_types=1);

namespace App\Helpers;

use Illuminate\Support\Str;

final class BrazilianAddressHelper
{
    public static function cepForValidation(mixed $value): mixed
    {
        if (is_string($value) && strlen($value) === 8 && ctype_digit($value)) {
            return self::formatCep($value);
        }

        return $value;
    }

    /**
     * Formata CEP (ex: 00000-000).
     */
    public static function formatCep(?string $value): string
    {
        if (blank($value)) {
            return '-';
        }

        $digits = DigitsHelper::only($value);

        if (Str::length($digits) !== 8) {
            return $value;
        }

        return sprintf(
            '%s-%s',
            substr($digits, 0, 5),
            substr($digits, 5, 3),
        );
    }
}
