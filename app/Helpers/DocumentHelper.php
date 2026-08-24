<?php

declare(strict_types=1);

namespace App\Helpers;

use Illuminate\Support\Str;

final class DocumentHelper
{
    /**
     * Remove todos os caracteres não numéricos.
     */
    public static function unmask(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * Formata CPF (ex: 000.000.000-00).
     */
    public static function formatCpf(?string $value): string
    {
        if (blank($value)) {
            return '-';
        }

        $digits = self::unmask($value);

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

        $digits = self::unmask($value);

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

        $digits = self::unmask($value);

        return match (Str::length($digits)) {
            11 => self::formatCpf($digits),
            14 => self::formatCnpj($digits),
            default => $value,
        };
    }

    /**
     * Formata telefone ou celular brasileiro, com ou sem DDI 55.
     */
    public static function formatPhone(?string $value): string
    {
        if (blank($value)) {
            return '-';
        }

        $digits = self::unmask($value);

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

    /**
     * Formata CEP (ex: 00000-000).
     */
    public static function formatCep(?string $value): string
    {
        if (blank($value)) {
            return '-';
        }

        $digits = self::unmask($value);

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
