<?php

namespace App\Support;

use App\Enums\HolidayScope;
use App\Models\City;
use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class HolidayCalendar
{
    /** @return list<int> */
    public function years(): array
    {
        $year = CarbonImmutable::now(config('app.timezone'))->year;

        return [$year, $year + 1, $year + 2];
    }

    public static function lock(): void
    {
        DB::statement("SELECT pg_advisory_xact_lock(hashtext('sge-holiday-data'))");
    }

    /** @return Builder<Holiday> */
    public function query(City $city, string $startsOn, string $endsOn): Builder
    {
        Validator::make(['starts_on' => $startsOn, 'ends_on' => $endsOn], ['starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on']])->validate();

        return Holiday::query()->whereBetween('date', [$startsOn, $endsOn])->where(function (Builder $query) use ($city): void {
            $query->where('scope', HolidayScope::National)
                ->orWhere(fn (Builder $query) => $query->where('scope', HolidayScope::State)->where('state_code', $city->state->value))
                ->orWhere(fn (Builder $query) => $query->where('scope', HolidayScope::Municipal)->where('city_id', $city->id));
        })->orderBy('date')->orderBy('name');
    }

    /** @return Collection<int, Holiday> */
    public function occurrences(City $city, string $startsOn, string $endsOn): Collection
    {
        return $this->query($city, $startsOn, $endsOn)->get();
    }

    /** @return list<string> */
    public function blockedDates(City $city, string $startsOn, string $endsOn): array
    {
        return array_values($this->occurrences($city, $startsOn, $endsOn)->map(fn (Holiday $holiday): string => $holiday->date->toDateString())->unique()->all());
    }
}
