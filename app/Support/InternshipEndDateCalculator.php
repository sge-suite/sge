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
     * @param  list<array{starts_on: string, ends_on: ?string, weekly_hours: array<string, mixed>}>  $workSchedules
     * @return array{completion_date: CarbonImmutable, projected_end_date: CarbonImmutable, credited_hours: int, calendar: list<array{date: string, scheduled_hours: int, credited_hours: int, accumulated_hours: int, kind: string}>}
     */
    public function handle(CarbonImmutable $start, int $requiredHours, array $weeklyHours, array $blockedDates, int $marginDays, array $workSchedules = [], bool $includeCalendar = false): array
    {
        if ($requiredHours < 1 || $marginDays < 0 || ! $this->validWeeklyHours($weeklyHours)) {
            throw new InvalidArgumentException('A previsão exige carga e jornada válidas.');
        }

        $previousEnd = null;
        foreach ($workSchedules as $index => $schedule) {
            $scheduleStart = CarbonImmutable::createFromFormat('!Y-m-d', $schedule['starts_on'], $start->timezone);
            $scheduleEnd = $schedule['ends_on'] === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $schedule['ends_on'], $start->timezone);
            if ($scheduleStart === null || $scheduleStart->toDateString() !== $schedule['starts_on'] || $scheduleStart->lessThanOrEqualTo($start)
                || ($schedule['ends_on'] !== null && $scheduleEnd === null)
                || ($scheduleEnd !== null && ($scheduleEnd->toDateString() !== $schedule['ends_on'] || $scheduleEnd->lessThan($scheduleStart)))
                || ($index > 0 && ($previousEnd === null || ! $previousEnd->addDay()->isSameDay($scheduleStart)))
                || ! $this->validWeeklyHours($schedule['weekly_hours'])) {
                throw new InvalidArgumentException('As vigências de jornada devem ser válidas, ordenadas e contínuas.');
            }
            $previousEnd = $scheduleEnd;
        }

        $blocked = array_fill_keys($blockedDates, true);
        $limit = $start->addYears(10);
        $remaining = $requiredHours;
        $completion = null;
        $calendar = [];
        for ($day = $start->startOfDay(); $day->lessThanOrEqualTo($limit); $day = $day->addDay()) {
            $hours = $this->hoursOn($day, $weeklyHours, $workSchedules);
            $isBlocked = isset($blocked[$day->toDateString()]);
            $credited = $isBlocked ? 0 : min($hours, $remaining);
            $remaining -= $credited;
            if ($includeCalendar) {
                $calendar[] = ['date' => $day->toDateString(), 'scheduled_hours' => $hours, 'credited_hours' => $credited,
                    'accumulated_hours' => $requiredHours - $remaining, 'kind' => $isBlocked ? 'blocked' : ($hours === 0 ? 'no_schedule' : 'work_day')];
            }
            if ($remaining === 0) {
                $completion = $day;
                break;
            }
        }
        if ($completion !== null) {
            $marginEnd = $completion->addDays($marginDays);
            for ($day = $completion; $day->lessThanOrEqualTo($limit); $day = $day->addDay()) {
                $isBlocked = isset($blocked[$day->toDateString()]);
                $hours = $this->hoursOn($day, $weeklyHours, $workSchedules);
                $isEnd = $day->greaterThanOrEqualTo($marginEnd) && ! $isBlocked && $hours > 0;
                if ($includeCalendar && $day->greaterThan($completion)) {
                    $calendar[] = ['date' => $day->toDateString(), 'scheduled_hours' => $hours, 'credited_hours' => 0,
                        'accumulated_hours' => $requiredHours, 'kind' => $isBlocked ? 'blocked' : ($isEnd ? 'projected_end' : ($day->lessThan($marginEnd) ? 'safety_margin' : 'no_schedule'))];
                }
                if ($isEnd) {
                    return ['completion_date' => $completion, 'projected_end_date' => $day, 'credited_hours' => $requiredHours, 'calendar' => $calendar];
                }
            }
        }

        throw new InvalidArgumentException('A previsão ultrapassa o limite de dez anos.');
    }

    /** @param array<string, mixed> $weeklyHours */
    private function validWeeklyHours(array $weeklyHours): bool
    {
        return count($weeklyHours) === 7 && array_diff(self::DAYS, array_keys($weeklyHours)) === []
            && array_filter($weeklyHours, fn (mixed $hours): bool => ! is_int($hours) || $hours < 0 || $hours > 24) === []
            && array_sum($weeklyHours) > 0;
    }

    /**
     * @param  array<string, mixed>  $weeklyHours
     * @param  list<array{starts_on: string, ends_on: ?string, weekly_hours: array<string, mixed>}>  $workSchedules
     */
    private function hoursOn(CarbonImmutable $day, array $weeklyHours, array $workSchedules): int
    {
        $date = $day->toDateString();
        foreach (array_reverse($workSchedules) as $schedule) {
            if ($date >= $schedule['starts_on']) {
                if ($schedule['ends_on'] !== null && $date > $schedule['ends_on']) {
                    throw new InvalidArgumentException('Há uma lacuna nas vigências de jornada.');
                }

                return $schedule['weekly_hours'][self::DAYS[$day->dayOfWeek]];
            }
        }

        return $weeklyHours[self::DAYS[$day->dayOfWeek]];
    }
}
