<?php

namespace App\Actions;

use App\Models\HolidayImportRun;
use App\Models\HolidaySource;
use App\Models\InternshipRequest;
use App\Support\HolidayCalendar;
use App\Support\InternshipEndDateCalculator;
use App\Support\InternshipPlannedPauses;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ProjectInternshipRequestEndDate
{
    public function __construct(private HolidayCalendar $calendar, private InternshipEndDateCalculator $calculator) {}

    public function handle(InternshipRequest $request): CarbonImmutable
    {
        $type = $request->internship_type_snapshot['rules'] ?? $request->internshipType()->first()?->getAttributes();
        $city = $request->workplaceAddress()->with('city')->first()?->city;
        if ($city === null && $request->granting_party_id !== null) {
            $city = $request->grantingParty()->with('address.city')->first()?->address?->city;
        }
        if ($city === null) {
            $proposal = $request->grantingPartyRegistrationRequest()->first();
            $city = $proposal === null ? null : $proposal->proposedCity()->first();
        }
        if ($city === null || $type === null || $request->planned_start_date === null || ! is_array($request->weekly_hours)) {
            throw ValidationException::withMessages(['projected_end_date' => 'Complete o tipo, o endereço da concedente, a jornada e a data inicial para calcular o término.']);
        }
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

        return $end;
    }
}
