<?php

namespace App\Helpers {

    use Carbon\Carbon;
    use Illuminate\Support\Str;

    class DateHelper
    {
        public static function format(string|Carbon|null $date): string
        {
            if (is_null($date)) {
                return '-';
            }

            return Carbon::parse($date)->timezone(config('app.timezone'))->isoFormat('D [de] MMMM [de] YYYY');
        }

        public static function formatShort(string|Carbon|null $date): string
        {
            if (is_null($date)) {
                return '-';
            }

            return Carbon::parse($date)->timezone(config('app.timezone'))->isoFormat('DD/MM/YYYY');
        }

        public static function formatRelative(string|Carbon|null $date): string
        {
            if (is_null($date)) {
                return '-';
            }

            return Carbon::parse($date)->timezone(config('app.timezone'))->diffForHumans();
        }

        public static function formatDateTime(string|Carbon|null $date): string
        {
            if (is_null($date)) {
                return '-';
            }

            return Carbon::parse($date)->timezone(config('app.timezone'))->format('d/m/Y \à\s H:i');
        }

        public static function formatMonthYear(string|Carbon|null $date): string
        {
            if (is_null($date)) {
                return '-';
            }

            return Carbon::parse($date)->timezone(config('app.timezone'))->isoFormat('MM/YYYY');
        }

        public static function formatMonthYearFull(string|Carbon|null $date): string
        {
            if (is_null($date)) {
                return '-';
            }

            return Str::title(Carbon::parse($date)->timezone(config('app.timezone'))->isoFormat('MMMM YYYY'));
        }
    }
}

namespace {

    use App\Helpers\DateHelper;
    use Carbon\Carbon;

    if (! function_exists('formatDate')) {
        function formatDate(string|Carbon|null $date): string
        {
            return DateHelper::format($date);
        }
    }

    if (! function_exists('formatShort')) {
        function formatShort(string|Carbon|null $date): string
        {
            return DateHelper::formatShort($date);
        }
    }

    if (! function_exists('formatDateTime')) {
        function formatDateTime(string|Carbon|null $date): string
        {
            return DateHelper::formatDateTime($date);
        }
    }

    if (! function_exists('formatRelative')) {
        function formatRelative(string|Carbon|null $date): string
        {
            return DateHelper::formatRelative($date);
        }
    }

    if (! function_exists('formatMonthYear')) {
        function formatMonthYear(string|Carbon|null $date): string
        {
            return DateHelper::formatMonthYear($date);
        }
    }

    if (! function_exists('formatMonthYearFull')) {
        function formatMonthYearFull(string|Carbon|null $date): string
        {
            return DateHelper::formatMonthYearFull($date);
        }
    }
}
