<?php

declare(strict_types=1);

namespace App\Helpers;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
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

    public static function formatWeekday(string|DateTimeInterface|null $date): string
    {
        if (is_null($date)) {
            return '-';
        }

        return Str::ucfirst(self::parse($date)->isoFormat('dddd'));
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

    public static function isAdult(?string $birthDate, ?DateTimeInterface $onDate = null): ?bool
    {
        if (blank($birthDate)) {
            return null;
        }

        $timezone = config('app.timezone');
        $reference = ($onDate === null ? CarbonImmutable::now($timezone) : CarbonImmutable::instance($onDate)->setTimezone($timezone))->startOfDay();
        try {
            $birth = CarbonImmutable::createFromFormat('!Y-m-d', $birthDate, $timezone);
        } catch (InvalidFormatException) {
            return null;
        }
        if ($birth === null || $birth->toDateString() !== $birthDate || $birth->isAfter($reference)) {
            return null;
        }

        return $birth->addYears(18)->lessThanOrEqualTo($reference);
    }

    private static function parse(string|DateTimeInterface $date): Carbon
    {
        return Carbon::parse($date)->timezone(config('app.timezone'));
    }
}
