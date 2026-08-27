<?php

declare(strict_types=1);

namespace App\Helpers;

use Illuminate\Support\Str;

final class BrazilianContactHelper
{
    /**
     * Formata telefone ou celular brasileiro, com ou sem DDI 55.
     */
    public static function formatPhone(?string $value): string
    {
        if (blank($value)) {
            return '-';
        }

        $digits = DigitsHelper::only($value);

        $length = Str::length($digits);

        if (in_array($length, [12, 13], true) && ! Str::startsWith($digits, '55')) {
            return $value;
        }

        return match ($length) {
            13 => sprintf(
                '+%s (%s) %s-%s',
                substr($digits, 0, 2),
                substr($digits, 2, 2),
                substr($digits, 4, 5),
                substr($digits, 9, 4),
            ),
            12 => sprintf(
                '+%s (%s) %s-%s',
                substr($digits, 0, 2),
                substr($digits, 2, 2),
                substr($digits, 4, 4),
                substr($digits, 8, 4),
            ),
            11 => sprintf(
                '(%s) %s-%s',
                substr($digits, 0, 2),
                substr($digits, 2, 5),
                substr($digits, 7, 4),
            ),
            10 => sprintf(
                '(%s) %s-%s',
                substr($digits, 0, 2),
                substr($digits, 2, 4),
                substr($digits, 6, 4),
            ),
            9 => sprintf(
                '%s-%s',
                substr($digits, 0, 5),
                substr($digits, 5, 4),
            ),
            8 => sprintf(
                '%s-%s',
                substr($digits, 0, 4),
                substr($digits, 4, 4),
            ),
            default => $value,
        };
    }
}
