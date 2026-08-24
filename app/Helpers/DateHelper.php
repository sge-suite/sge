<?php

declare(strict_types=1);

namespace App\Helpers;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Str;

final class DateHelper
{
    public static function format(string|DateTimeInterface|null $date): string
    {
        if (is_null($date)) {
            return '-';
        }

        return self::parse($date)->isoFormat('D [de] MMMM [de] YYYY');
    }

    public static function formatShort(string|DateTimeInterface|null $date): string
    {
        if (is_null($date)) {
            return '-';
        }

        return self::parse($date)->isoFormat('DD/MM/YYYY');
    }

    public static function formatRelative(string|DateTimeInterface|null $date): string
    {
        if (is_null($date)) {
            return '-';
        }

        return self::parse($date)->diffForHumans();
    }

    public static function formatDateTime(string|DateTimeInterface|null $date): string
    {
        if (is_null($date)) {
            return '-';
        }

        return self::parse($date)->format('d/m/Y \à\s H:i');
    }

    public static function formatMonthYear(string|DateTimeInterface|null $date): string
    {
        if (is_null($date)) {
            return '-';
        }

        return self::parse($date)->isoFormat('MM/YYYY');
    }

    public static function formatMonthYearFull(string|DateTimeInterface|null $date): string
    {
        if (is_null($date)) {
            return '-';
        }

        return Str::title(self::parse($date)->isoFormat('MMMM YYYY'));
    }

    private static function parse(string|DateTimeInterface $date): Carbon
    {
        return Carbon::parse($date)->timezone(config('app.timezone'));
    }
}
