<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class InternshipEndDateCalculator
{
    public const DAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /**
     * @param  array<string, mixed>  $weeklyHours
     * @param  list<string>  $blockedDates  Feriados, pausas e exceções já resolvidos pelo chamador.
     * @return array{completion_date: CarbonImmutable, projected_end_date: CarbonImmutable, credited_hours: int}
     */
    public function handle(CarbonImmutable $start, int $requiredHours, array $weeklyHours, array $blockedDates, int $marginDays): array
    {
        if ($requiredHours < 1 || $marginDays < 0 || count($weeklyHours) !== 7
            || array_diff(self::DAYS, array_keys($weeklyHours)) !== []
            || array_filter($weeklyHours, fn (mixed $hours): bool => ! is_int($hours) || $hours < 0 || $hours > 24) !== []
            || array_sum($weeklyHours) < 1) {
            throw new InvalidArgumentException('A previsão exige carga e jornada válidas.');
        }

        $blocked = array_fill_keys($blockedDates, true);
        $limit = $start->addYears(10);
        $remaining = $requiredHours;
        $completion = null;
        for ($day = $start->startOfDay(); $day->lessThanOrEqualTo($limit); $day = $day->addDay()) {
            $hours = $weeklyHours[self::DAYS[$day->dayOfWeek]];
            if (isset($blocked[$day->toDateString()]) || $hours === 0) {
                continue;
            }
            $remaining -= min($hours, $remaining);
            if ($remaining === 0) {
                $completion = $day;
                break;
            }
        }
        if ($completion !== null) {
            for ($day = $completion->addDays($marginDays); $day->lessThanOrEqualTo($limit); $day = $day->addDay()) {
                if (! isset($blocked[$day->toDateString()]) && $weeklyHours[self::DAYS[$day->dayOfWeek]] > 0) {
                    return ['completion_date' => $completion, 'projected_end_date' => $day, 'credited_hours' => $requiredHours];
                }
            }
        }

        throw new InvalidArgumentException('A previsão ultrapassa o limite de dez anos.');
    }
}
