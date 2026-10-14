<?php

namespace App\Actions;

use App\Models\Holiday;
use App\Models\HolidaySource;
use App\Models\User;
use App\Support\HolidayAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ResolveHolidaySource
{
    public function __construct(private HolidayAccess $access, private ManageHoliday $manage) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, HolidaySource $target, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $target);
        $data = Validator::make($data, HolidaySource::resolutionRules($data))->validate();
        $this->access->asAdministrator($actor, function () use ($actor, $target, $data): void {
            $source = HolidaySource::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('update', $source);
            if ($source->status !== 'pending') {
                throw ValidationException::withMessages(['source' => 'Essa pendência já foi resolvida.']);
            }
            $resolution = $data['resolution'];
            if ($resolution === 'ignore') {
                $source->status = 'ignored';
            } else {
                $holiday = $resolution === 'link'
                    ? Holiday::withTrashed()->lockForUpdate()->findOrFail((int) $data['holiday_id'])
                    : $this->manage->handle($actor, null, 'create', array_intersect_key($data, Holiday::inputRules()));
                $source->fill(['holiday_id' => $holiday->id, 'status' => 'linked']);
            }
            $source->resolution_notes = $data['resolution_notes'];
            $source->save();
        });
    }
}
