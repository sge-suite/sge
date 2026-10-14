<?php

namespace App\Actions;

use App\Enums\HolidayScope;
use App\Models\Holiday;
use App\Models\HolidayRecurrence;
use App\Support\HolidayCalendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateMunicipalHolidays
{
    /** @param list<int> $years */
    public function handle(array $years, ?HolidayRecurrence $target = null, bool $editing = false): void
    {
        $query = HolidayRecurrence::query();
        if ($target !== null) {
            $query->whereKey($target->id);
        }
        foreach ($query->get() as $recurrence) {
            DB::transaction(function () use ($recurrence, $years, $editing): void {
                HolidayCalendar::lock();
                $recurrence = HolidayRecurrence::query()->with('city')->lockForUpdate()->findOrFail($recurrence->id);
                $currentYear = now(config('app.timezone'))->year;
                $generatedYears = $recurrence->holidays()->withTrashed()->where('occurrence_year', '>', $currentYear)->pluck('occurrence_year')->all();
                foreach (array_unique([...$years, ...$generatedYears]) as $year) {
                    $holiday = $recurrence->holidays()->withTrashed()->where('occurrence_year', $year)->first();
                    if ($holiday?->is_manually_adjusted || ($editing && $year <= $currentYear)) {
                        continue;
                    }
                    $valid = ($recurrence->starts_year === null || $year >= $recurrence->starts_year)
                        && ($recurrence->ends_year === null || $year <= $recurrence->ends_year)
                        && checkdate($recurrence->month, $recurrence->day, $year);
                    if (! $valid) {
                        if ($holiday !== null && ! $holiday->trashed() && $year > $currentYear) {
                            $holiday->delete();
                        }

                        continue;
                    }
                    if ($holiday?->trashed()) {
                        continue;
                    }
                    $date = sprintf('%04d-%02d-%02d', $year, $recurrence->month, $recurrence->day);
                    $attributes = ['date' => $date, 'name' => $recurrence->name, 'scope' => HolidayScope::Municipal, 'city_id' => $recurrence->city_id, 'state_code' => $recurrence->city->state];
                    if ($holiday !== null) {
                        if ($year > $currentYear) {
                            $collision = Holiday::query()->where('date', $date)->where('name', $recurrence->name)->where('scope', HolidayScope::Municipal)->where('city_id', $recurrence->city_id)->whereKeyNot($holiday->id)->exists();
                            if ($collision) {
                                throw ValidationException::withMessages(['name' => 'A alteração colide com outro feriado municipal já cadastrado.']);
                            }
                            $holiday->fill($attributes)->save();
                        }

                        continue;
                    }
                    $existing = Holiday::withTrashed()->where('date', $date)->where('name', $recurrence->name)->where('scope', HolidayScope::Municipal)->where('city_id', $recurrence->city_id)->first();
                    if ($existing !== null) {
                        if ($existing->holiday_recurrence_id === null) {
                            $existing->fill(['holiday_recurrence_id' => $recurrence->id, 'occurrence_year' => $year, 'is_manually_adjusted' => true])->save();
                        }

                        continue;
                    }
                    Holiday::create([...$attributes, 'holiday_recurrence_id' => $recurrence->id, 'occurrence_year' => $year, 'origin' => 'recurrence']);
                }
            });
        }
    }
}
