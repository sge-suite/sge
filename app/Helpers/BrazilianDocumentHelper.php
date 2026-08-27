<?php

declare(strict_types=1);

namespace App\Helpers;

use Illuminate\Support\Str;

final class BrazilianDocumentHelper
{
    /**
     * Formata CPF (ex: 000.000.000-00).
     */
    public static function formatCpf(?string $value): string
    {
        if (blank($value)) {
            return '-';
        }

        $digits = DigitsHelper::only($value);

        if (Str::length($digits) !== 11) {
            return $value;
        }

        return sprintf(
            '%s.%s.%s-%s',
            substr($digits, 0, 3),
            substr($digits, 3, 3),
            substr($digits, 6, 3),
            substr($digits, 9, 2),
        );
    }

    /**
     * Formata CNPJ (ex: 00.000.000/0000-00).
     */
    public static function formatCnpj(?string $value): string
    {
        if (blank($value)) {
            return '-';
        }

        $digits = DigitsHelper::only($value);

        if (Str::length($digits) !== 14) {
            return $value;
        }

        return sprintf(
            '%s.%s.%s/%s-%s',
            substr($digits, 0, 2),
            substr($digits, 2, 3),
            substr($digits, 5, 3),
            substr($digits, 8, 4),
            substr($digits, 12, 2),
        );
    }

    /**
     * Formata CPF ou CNPJ automaticamente baseado no tamanho.
     */
    public static function formatCpfCnpj(?string $value): string
    {
        if (blank($value)) {
            return '-';
        }

        $digits = DigitsHelper::only($value);

        return match (Str::length($digits)) {
            11 => self::formatCpf($digits),
            14 => self::formatCnpj($digits),
            default => $value,
        };
    }
}
