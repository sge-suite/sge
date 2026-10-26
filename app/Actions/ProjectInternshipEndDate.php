<?php

namespace App\Actions;

use App\Models\City;
use App\Models\HolidayImportRun;
use App\Models\HolidaySource;
use App\Models\Internship;
use App\Models\InternshipWorkSchedule;
use App\Support\HolidayCalendar;
use App\Support\InternshipCalculationHistory;
use App\Support\InternshipEndDateCalculator;
use App\Support\InternshipPlannedPauses;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ProjectInternshipEndDate
{
    public function __construct(private HolidayCalendar $calendar, private InternshipEndDateCalculator $calculator,
        private InternshipPlannedPauses $plannedPauses, private InternshipCalculationHistory $history) {}

    public function handle(Internship $internship): CarbonImmutable
    {
        return $this->details($internship, includeCalendar: false)['projected_end_date'];
    }

    /**
     * @param  list<array{id: ?int, date: string, is_working_day: bool, reason: string}>|null  $overrides
     * @param  list<array{starts_on: string, ends_on: ?string, weekly_hours: array<string, int>}>|null  $workSchedules
     * @return array{completion_date: CarbonImmutable, projected_end_date: CarbonImmutable, credited_hours: int, calendar: list<array{date: string, scheduled_hours: int, credited_hours: int, accumulated_hours: int, kind: string, reason?: string}>}
     */
    public function details(Internship $internship, ?City $city = null, bool $includeCalendar = true, ?array $overrides = null, ?array $workSchedules = null): array
    {
        $rules = $internship->internship_type_snapshot['rules'] ?? [];
        $validator = Validator::make($rules, ['required_hours' => ['required', 'integer', 'min:1'], 'safety_margin_days' => ['required', 'integer', 'min:0']]);
        if ($validator->fails()) {
            throw ValidationException::withMessages(['projected_end_date' => 'As regras históricas do tipo precisam informar a carga exigida e a margem para o cálculo.']);
        }
        $city ??= $internship->workplaceAddress()->with('city')->first()?->city;
        if ($city === null) {
            throw ValidationException::withMessages(['projected_end_date' => 'O endereço histórico do local de estágio precisa de um município válido.']);
        }

        HolidayCalendar::lock();
        $start = CarbonImmutable::parse($internship->planned_start_date->toDateString(), config('app.timezone'));
        $horizon = $start->addYears(10)->toDateString();
        $blocked = [];
        foreach ($this->calendar->occurrences($city, $start->toDateString(), $horizon) as $holiday) {
            $date = $holiday->date->toDateString();
            $blocked[$date] = isset($blocked[$date]) ? $blocked[$date].'; '.$holiday->name : 'Feriado: '.$holiday->name;
        }
        $overrides ??= $internship->calendarOverrides()->whereBetween('date', [$start->toDateString(), $horizon])->get()
            ->map(fn ($override): array => ['id' => $override->id, 'date' => $override->date->toDateString(),
                'is_working_day' => $override->is_working_day, 'reason' => $override->reason])->values()->all();
        foreach ($overrides as $override) {
            if ($override['is_working_day']) {
                unset($blocked[$override['date']]);
            } else {
                $blocked[$override['date']] = 'Expediente bloqueado: '.$override['reason'];
            }
        }
        foreach ($this->plannedPauses->blockedDates($internship->planned_pauses ?? [], $start->toDateString()) as $date) {
            $blocked[$date] = 'Pausa prevista';
        }
        foreach ($internship->pauses()->where('starts_at', '<=', $horizon)->where('ends_at', '>=', $start->toDateString())->get() as $pause) {
            $end = min($pause->ends_at->toDateString(), $horizon);
            for ($day = CarbonImmutable::parse(max($pause->starts_at->toDateString(), $start->toDateString()), config('app.timezone')); $day->toDateString() <= $end; $day = $day->addDay()) {
                $blocked[$day->toDateString()] = 'Pausa: '.$pause->reason;
            }
        }
        $schedules = $workSchedules ?? array_values($internship->workSchedules()->orderBy('starts_on')->get()->map(function (InternshipWorkSchedule $schedule): array {
            if ($schedule->starts_on === null) {
                throw ValidationException::withMessages(['projected_end_date' => 'Uma vigência histórica está sem data de início.']);
            }

            return ['starts_on' => $schedule->starts_on->toDateString(), 'ends_on' => $schedule->ends_on?->toDateString(), 'weekly_hours' => $schedule->weekly_hours];
        })->all());

        try {
            $result = $this->calculator->handle($start, (int) $rules['required_hours'], $internship->weekly_hours, array_keys($blocked), (int) $rules['safety_margin_days'], $schedules, $includeCalendar);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['projected_end_date' => $exception->getMessage()]);
        }
        $end = $result['projected_end_date'];
        foreach (range($start->year, $end->year) as $year) {
            $runs = HolidayImportRun::query()->where('year', $year)->whereIn('territory', ['national', $city->state->value])
                ->where('status', 'completed')->whereNotNull('completed_at')->pluck('id');
            if ($runs->count() !== 2 || HolidaySource::query()->whereIn('holiday_import_run_id', $runs)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['projected_end_date' => 'O calendário do período precisa estar completo e revisado antes de recalcular o término.']);
            }
        }

        foreach ($result['calendar'] as &$day) {
            $day['reason'] = $blocked[$day['date']] ?? match ($day['kind']) {
                'work_day' => 'Dia de estágio', 'safety_margin' => 'Margem de segurança',
                'projected_end' => 'Término previsto', default => 'Sem jornada neste dia',
            };
        }
        unset($day);

        $internship->calculationSnapshot = $this->history->snapshot([
            'start' => $start->toDateString(), 'required_hours' => (int) $rules['required_hours'],
            'weekly_hours' => $internship->weekly_hours, 'blocked_dates' => array_keys($blocked),
            'margin_days' => (int) $rules['safety_margin_days'], 'work_schedules' => $schedules,
        ], $result, $city->name.' / '.$city->state->value, $blocked);

        return $result;
    }
}
