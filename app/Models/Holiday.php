<?php

namespace App\Models;

use App\Concerns\HolidayValidationRules;
use App\Enums\BrazilianState;
use App\Enums\HolidayScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property CarbonImmutable|null $date
 * @property string $name
 * @property HolidayScope $scope
 * @property BrazilianState|null $state_code
 * @property int|null $city_id
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'date',
    'name',
    'scope',
    'state_code',
    'city_id',
])]
class Holiday extends Model
{
    use HolidayValidationRules;
    use LogsActivity;
    use SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'scope' => HolidayScope::class,
            'state_code' => BrazilianState::class,
        ];
    }

    /**
     * Get the city to which this holiday applies.
     *
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * Scope a query to a Brazilian state.
     *
     * @param  Builder<Holiday>  $query
     * @return Builder<Holiday>
     */
    public function scopeForState(Builder $query, BrazilianState|string $state): Builder
    {
        $stateValue = $state instanceof BrazilianState
            ? $state->value
            : Str::upper(trim($state));

        return $query->where('state_code', $stateValue);
    }

    /**
     * Validate the location fields required by each holiday scope.
     */
    protected static function booted(): void
    {
        static::saving(function (self $holiday): void {
            $attributes = $holiday->getAttributes();
            $attributes['date'] = $holiday->date?->toDateString();
            $validator = Validator::make($attributes, $holiday->holidayRules());

            if ($validator->fails()) {
                throw new InvalidArgumentException($validator->errors()->first());
            }

            match ($holiday->scope) {
                HolidayScope::National => self::validateNationalScope($holiday),
                HolidayScope::State => self::validateStateScope($holiday),
                HolidayScope::Municipal => self::validateMunicipalScope($holiday),
            };
        });
    }

    private static function validateNationalScope(self $holiday): void
    {
        if ($holiday->state_code !== null || $holiday->city_id !== null) {
            throw new InvalidArgumentException('Feriados nacionais não podem ter UF ou cidade vinculada.');
        }
    }

    private static function validateStateScope(self $holiday): void
    {
        if ($holiday->state_code === null || $holiday->city_id !== null) {
            throw new InvalidArgumentException('Feriados estaduais exigem uma UF e não podem ter cidade vinculada.');
        }
    }

    private static function validateMunicipalScope(self $holiday): void
    {
        if ($holiday->city_id === null) {
            throw new InvalidArgumentException('Feriados municipais exigem uma cidade vinculada.');
        }

        $city = City::query()->find($holiday->city_id);

        if ($city === null) {
            throw new InvalidArgumentException('A cidade vinculada ao feriado municipal não existe.');
        }

        if ($holiday->state_code === null) {
            $holiday->state_code = $city->state;

            return;
        }

        if ($city->state !== $holiday->state_code) {
            throw new InvalidArgumentException('A UF do feriado municipal deve corresponder à UF da cidade vinculada.');
        }
    }
}
