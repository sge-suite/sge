<?php

namespace App\Actions;

use App\Enums\InternshipRequestStatus;
use App\Models\City;
use App\Models\HolidayImportRun;
use App\Models\HolidaySource;
use App\Models\InternshipRequest;
use App\Rules\WorkingInternshipStartDate;
use App\Support\HolidayCalendar;
use App\Support\InternshipCalculationHistory;
use App\Support\InternshipEndDateCalculator;
use App\Support\InternshipPlannedPauses;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ProjectInternshipRequestEndDate
{
    public function __construct(private HolidayCalendar $calendar, private InternshipEndDateCalculator $calculator,
        private InternshipCalculationHistory $history) {}

    public function handle(InternshipRequest $request): CarbonImmutable
    {
        $type = $request->internship_type_snapshot['rules'] ?? $request->internshipType()->first()?->getAttributes();
        $city = $this->workplaceCity($request);
        if ($city === null || $type === null || $request->planned_start_date === null || ! is_array($request->weekly_hours)) {
            throw ValidationException::withMessages(['projected_end_date' => 'Complete o tipo, o endereço da concedente, a jornada e a data inicial para calcular o término.']);
        }
        $this->validateStartDate($request, $city);
        HolidayCalendar::lock();
        $start = CarbonImmutable::parse($request->planned_start_date->toDateString(), config('app.timezone'));
        $horizon = $start->addYears(10);
        $blocked = $this->calendar->blockedDates($city, $start->toDateString(), $horizon->toDateString());
        $blocked = array_values(array_unique([...$blocked, ...app(InternshipPlannedPauses::class)->blockedDates($request->planned_pauses ?? [], $start->toDateString())]));
        try {
            $result = $this->calculator->handle($start, (int) $type['required_hours'], $request->weekly_hours, $blocked, (int) $type['safety_margin_days']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['weekly_hours' => $exception->getMessage()]);
        }
        $end = $result['projected_end_date'];
        foreach (range($start->year, $end->year) as $year) {
            $runs = HolidayImportRun::query()->where('year', $year)->whereIn('territory', ['national', $city->state->value])
                ->where('status', 'completed')->whereNotNull('completed_at')->pluck('id');
            if ($runs->count() !== 2 || HolidaySource::query()->whereIn('holiday_import_run_id', $runs)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['projected_end_date' => 'O calendário do período precisa ser preparado ou revisado pelo Setor. Seu preenchimento pode ser salvo.']);
            }
        }

        $request->calculationSnapshot = $this->history->snapshot([
            'start' => $start->toDateString(), 'required_hours' => (int) $type['required_hours'],
            'weekly_hours' => $request->weekly_hours, 'blocked_dates' => $blocked,
            'margin_days' => (int) $type['safety_margin_days'], 'work_schedules' => [],
        ], $result, $city->name.' / '.$city->state->value);

        return $end;
    }

    public function validateStartDate(InternshipRequest $request, ?City $city = null): void
    {
        if ($request->planned_start_date === null || ! is_array($request->weekly_hours) || array_sum($request->weekly_hours) < 1) {
            return;
        }
        $date = $request->planned_start_date->toDateString();
        $city ??= $this->workplaceCity($request);
        $blocked = [];
        if ($city !== null) {
            HolidayCalendar::lock();
            foreach ($this->calendar->occurrences($city, $date, $date) as $holiday) {
                $blocked[$date] = isset($blocked[$date]) ? $blocked[$date].'; '.$holiday->name : 'Feriado: '.$holiday->name;
            }
        }
        foreach ($request->planned_pauses ?? [] as $pause) {
            if (filled($pause['starts_at'] ?? null) && filled($pause['ends_at'] ?? null)
                && $date >= $pause['starts_at'] && $date <= $pause['ends_at']) {
                $blocked[$date] = 'Pausa prevista';
            }
        }
        Validator::make(['planned_start_date' => $date], [
            'planned_start_date' => [new WorkingInternshipStartDate($request->weekly_hours, $blocked)],
        ])->validate();
    }

    private function workplaceCity(InternshipRequest $request): ?City
    {
        if (in_array($request->status, [InternshipRequestStatus::Draft, InternshipRequestStatus::PendingCorrection], true)) {
            if ($request->granting_party_id !== null) {
                return $request->grantingParty()->with('address.city')->first()?->address?->city;
            }
            if ($request->granting_party_registration_request_id !== null) {
                return $request->grantingPartyRegistrationRequest()->first()?->proposedCity()->first();
            }
        }
        $city = $request->workplaceAddress()->with('city')->first()?->city;
        if ($city === null && $request->granting_party_id !== null) {
            $city = $request->grantingParty()->with('address.city')->first()?->address?->city;
        }
        if ($city === null) {
            $proposal = $request->grantingPartyRegistrationRequest()->first();
            $city = $proposal === null ? null : $proposal->proposedCity()->first();
        }

        return $city;
    }
}
