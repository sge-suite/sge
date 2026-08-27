<?php

declare(strict_types=1);

namespace App\Helpers;

final class DigitsHelper
{
    /**
     * Retorna somente os dígitos do valor informado.
     */
    public static function only(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
