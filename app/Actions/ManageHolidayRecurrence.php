<?php

namespace App\Actions;

use App\Models\HolidayRecurrence;
use App\Models\User;
use App\Support\HolidayAccess;
use App\Support\HolidayCalendar;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class ManageHolidayRecurrence
{
    public function __construct(private HolidayAccess $access, private HolidayCalendar $calendar, private GenerateMunicipalHolidays $generate) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, ?HolidayRecurrence $target, array $data = [], string $operation = 'save'): HolidayRecurrence
    {
        abort_unless(in_array($operation, ['save', 'delete', 'restore'], true), 404);
        abort_if($operation !== 'save' && $target === null, 404);
        $ability = $operation === 'save' ? ($target === null ? 'create' : 'update') : $operation;
        Gate::forUser($actor)->authorize($ability, $target ?? HolidayRecurrence::class);

        return $this->access->asAdministrator($actor, function () use ($actor, $target, $data, $ability, $operation): HolidayRecurrence {
            $recurrence = $target === null ? new HolidayRecurrence : HolidayRecurrence::withTrashed()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize($ability, $target === null ? HolidayRecurrence::class : $recurrence);
            if ($operation === 'delete') {
                $recurrence->delete();

                return $recurrence;
            }
            if ($operation === 'restore') {
                $recurrence->restore();
                $this->generate->handle($this->calendar->years(), $recurrence);

                return $recurrence;
            }
            $validated = Validator::make($data, HolidayRecurrence::rules())->validate();
            $recurrence->fill($validated);
            $recurrence->save();
            $years = $this->calendar->years();
            $this->generate->handle($years, $recurrence, $target !== null);

            return $recurrence;
        });
    }
}
