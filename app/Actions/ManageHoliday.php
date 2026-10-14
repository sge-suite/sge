<?php

namespace App\Actions;

use App\Enums\HolidayScope;
use App\Models\City;
use App\Models\Holiday;
use App\Models\User;
use App\Support\HolidayAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageHoliday
{
    public function __construct(private HolidayAccess $access) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, ?Holiday $target, string $operation, array $data = []): Holiday
    {
        abort_unless(in_array($operation, ['create', 'update', 'delete', 'restore'], true), 404);
        abort_unless(($operation === 'create') === ($target === null), 404);
        Gate::forUser($actor)->authorize($operation, $target ?? Holiday::class);

        return $this->access->asAdministrator($actor, function () use ($actor, $target, $operation, $data): Holiday {
            $holiday = $target === null ? new Holiday : Holiday::withTrashed()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize($operation, $target === null ? Holiday::class : $holiday);
            if ($operation === 'delete') {
                $holiday->is_manually_adjusted = true;
                $holiday->save();
                $holiday->delete();
            } elseif ($operation === 'restore') {
                $duplicate = Holiday::query()->where('date', $holiday->date->toDateString())->where('name', $holiday->name)
                    ->where('scope', $holiday->scope)->where('state_code', $holiday->state_code?->value)->where('city_id', $holiday->city_id)->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages(['holiday' => 'Já existe um feriado ativo com esses dados.']);
                }
                $holiday->is_manually_adjusted = true;
                $holiday->restore();
            } else {
                $validated = Validator::make($data, Holiday::inputRules())->validate();
                $validated += ['state_code' => null, 'city_id' => null];
                $holiday->fill($validated);
                if ($target === null) {
                    $holiday->origin = 'manual';
                } else {
                    $holiday->is_manually_adjusted = true;
                }
                $duplicate = Holiday::query()->where('date', $validated['date'])->where('name', trim($validated['name']))
                    ->where('scope', $validated['scope'])->where('state_code', $validated['state_code'] ?? ($holiday->scope === HolidayScope::Municipal ? City::find((int) $validated['city_id'])?->state->value : null))
                    ->where('city_id', $validated['city_id'] ?? null)->when($holiday->exists, fn ($query) => $query->whereKeyNot($holiday->id))->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages(['name' => 'Já existe um feriado ativo com esses dados.']);
                }
                try {
                    $holiday->save();
                } catch (\InvalidArgumentException $exception) {
                    throw ValidationException::withMessages(['holiday' => $exception->getMessage()]);
                }
            }

            return $holiday;
        });
    }
}
