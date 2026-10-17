<?php

namespace App\Models;

use App\Concerns\HolidayValidationRules;
use App\Enums\BrazilianState;
use App\Enums\HolidayScope;
use Carbon\CarbonImmutable;
use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property CarbonImmutable|null $date
 * @property string $name
 * @property HolidayScope $scope
 * @property BrazilianState|null $state_code
 * @property string $origin
 * @property bool $is_manually_adjusted
 * @property int|null $holiday_recurrence_id
 * @property int|null $occurrence_year
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
    'origin',
    'is_manually_adjusted',
    'holiday_recurrence_id',
    'occurrence_year',
])]
class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory;

    use HolidayValidationRules;
    use LogsActivity;
    use Searchable;
    use SoftDeletes;

    protected $attributes = ['origin' => 'manual', 'is_manually_adjusted' => false];

    /** @return array{id: int, name: string, date: string|null, year: int|null, scope: string, state_code: string, city_id: int, origin: string} */
    public function toSearchableArray(): array
    {
        return [
            'id' => (int) $this->getKey(),
            'name' => $this->name,
            'date' => $this->date?->toDateString(),
            'year' => $this->date?->year,
            'scope' => $this->scope->value,
            'state_code' => $this->state_code->value ?? '',
            'city_id' => (int) ($this->city_id ?? 0),
            'origin' => $this->origin,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
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
            'is_manually_adjusted' => 'boolean',
            'occurrence_year' => 'integer',
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

    /** @return BelongsTo<HolidayRecurrence, $this> */
    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(HolidayRecurrence::class, 'holiday_recurrence_id')->withTrashed();
    }

    public function originLabel(): string
    {
        return match ($this->origin) {
            'manual' => 'Manual', 'brasil_api' => 'BrasilAPI', 'recurrence' => 'Recorrência municipal', default => 'Origem não identificada',
        };
    }

    /** @return array<string, array<int, mixed>> */
    public static function inputRules(): array
    {
        return (new self)->holidayRules();
    }

    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        $activity->properties = ($activity->properties ?? collect())->put('calendar_scope', [
            'scope' => $this->scope->value, 'state_code' => $this->state_code?->value, 'city_id' => $this->city_id,
        ])->put('calendar_previous_scope', $this->exists && $this->getRawOriginal('scope') !== null ? [
            'scope' => $this->getRawOriginal('scope'), 'state_code' => $this->getRawOriginal('state_code'), 'city_id' => $this->getRawOriginal('city_id'),
        ] : null);
    }

    /**
     * Validate the location fields required by each holiday scope.
     */
    protected static function booted(): void
    {
        static::saving(function (self $holiday): void {
            $holiday->name = trim($holiday->name);
            if (! in_array($holiday->origin, ['manual', 'brasil_api', 'recurrence'], true)) {
                throw new InvalidArgumentException('Origem inválida.');
            }
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

        static::forceDeleting(function (): never {
            throw new InvalidArgumentException('Feriados não podem ser excluídos fisicamente.');
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
