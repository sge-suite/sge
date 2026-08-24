<?php

declare(strict_types=1);

use App\Helpers\CurrencyHelper;
use App\Helpers\DateHelper;
use App\Helpers\DocumentHelper;

if (! function_exists('formatDate')) {
    function formatDate(string|DateTimeInterface|null $date): string
    {
        return DateHelper::format($date);
    }
}

if (! function_exists('formatShort')) {
    function formatShort(string|DateTimeInterface|null $date): string
    {
        return DateHelper::formatShort($date);
    }
}

if (! function_exists('formatDateTime')) {
    function formatDateTime(string|DateTimeInterface|null $date): string
    {
        return DateHelper::formatDateTime($date);
    }
}

if (! function_exists('formatRelative')) {
    function formatRelative(string|DateTimeInterface|null $date): string
    {
        return DateHelper::formatRelative($date);
    }
}

if (! function_exists('formatMonthYear')) {
    function formatMonthYear(string|DateTimeInterface|null $date): string
    {
        return DateHelper::formatMonthYear($date);
    }
}

if (! function_exists('formatMonthYearFull')) {
    function formatMonthYearFull(string|DateTimeInterface|null $date): string
    {
        return DateHelper::formatMonthYearFull($date);
    }
}

if (! function_exists('formatCurrency')) {
    function formatCurrency(int|float|null $value, string $currency = 'BRL', ?string $locale = 'pt_BR'): string
    {
        return CurrencyHelper::format($value, $currency, $locale);
    }
}

if (! function_exists('unmask')) {
    function unmask(?string $value): string
    {
        return DocumentHelper::unmask($value);
    }
}

if (! function_exists('formatCpf')) {
    function formatCpf(?string $value): string
    {
        return DocumentHelper::formatCpf($value);
    }
}

if (! function_exists('formatCnpj')) {
    function formatCnpj(?string $value): string
    {
        return DocumentHelper::formatCnpj($value);
    }
}

if (! function_exists('formatCpfCnpj')) {
    function formatCpfCnpj(?string $value): string
    {
        return DocumentHelper::formatCpfCnpj($value);
    }
}

if (! function_exists('formatPhone')) {
    function formatPhone(?string $value): string
    {
        return DocumentHelper::formatPhone($value);
    }
}

if (! function_exists('formatCep')) {
    function formatCep(?string $value): string
    {
        return DocumentHelper::formatCep($value);
    }
}
