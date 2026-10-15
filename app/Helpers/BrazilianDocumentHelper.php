<?php

declare(strict_types=1);

namespace App\Helpers;

use Illuminate\Support\Str;

final class BrazilianDocumentHelper
{
    public static function normalizeCnpj(string $value): string
    {
        return Str::upper(preg_replace('/[.\/\s-]/u', '', trim($value)) ?? '');
    }

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
     * Formata CNPJ numérico ou alfanumérico com a máscara padrão.
     */
    public static function formatCnpj(?string $value): string
    {
        if (blank($value)) {
            return '-';
        }

        $cnpj = self::normalizeCnpj($value);

        if (preg_match('/^[A-Z0-9]{12}[0-9]{2}$/', $cnpj) !== 1) {
            return $value;
        }

        return sprintf(
            '%s.%s.%s/%s-%s',
            substr($cnpj, 0, 2),
            substr($cnpj, 2, 3),
            substr($cnpj, 5, 3),
            substr($cnpj, 8, 4),
            substr($cnpj, 12, 2),
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

        $document = self::normalizeCnpj($value);

        return match (true) {
            strlen($document) === 11 && ctype_digit($document) => self::formatCpf($document),
            strlen($document) === 14 => self::formatCnpj($document),
            default => $value,
        };
    }
}
