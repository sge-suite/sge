<?php

namespace App\Models;

use Database\Factories\HolidayRecurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $name
 * @property int $city_id
 * @property int $month
 * @property int $day
 * @property int|null $starts_year
 * @property int|null $ends_year
 * @property string|null $notes
 * @property-read City $city
 */
#[Fillable(['name', 'city_id', 'month', 'day', 'starts_year', 'ends_year', 'notes'])]
class HolidayRecurrence extends Model
{
    /** @use HasFactory<HolidayRecurrenceFactory> */
    use HasFactory;

    use LogsActivity;
    use Searchable;
    use SoftDeletes;

    /** @return array{id: int, name: string} */
    public function toSearchableArray(): array
    {
        return ['id' => (int) $this->getKey(), 'name' => $this->name];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return array_fill_keys(['city_id', 'month', 'day', 'starts_year', 'ends_year'], 'integer');
    }

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'city_id' => ['required', 'integer', Rule::exists(City::class, 'id')],
            'month' => ['required', 'integer', 'between:1,12'],
            'day' => ['required', 'integer', 'between:1,31'],
            'starts_year' => ['nullable', 'integer', 'between:1900,2199'],
            'ends_year' => ['nullable', 'integer', 'between:1900,2199'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        $activity->properties = ($activity->properties ?? collect())->put('calendar_scope', [
            'scope' => 'municipal', 'city_id' => $this->city_id,
        ])->put('calendar_previous_scope', $this->exists && $this->getRawOriginal('city_id') !== null ? [
            'scope' => 'municipal', 'city_id' => $this->getRawOriginal('city_id'),
        ] : null);
    }

    /** @return HasMany<Holiday, $this> */
    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $recurrence): void {
            $recurrence->name = trim($recurrence->name);
            Validator::make($recurrence->getAttributes(), self::rules())->validate();
            if (! checkdate($recurrence->month, $recurrence->day, 2000)) {
                throw ValidationException::withMessages(['day' => 'Dia inválido para o mês escolhido.']);
            }
            if ($recurrence->starts_year !== null && $recurrence->ends_year !== null && $recurrence->ends_year < $recurrence->starts_year) {
                throw ValidationException::withMessages(['ends_year' => 'O ano final deve ser igual ou posterior ao inicial.']);
            }
            if (self::withTrashed()->where('name', $recurrence->name)->where('city_id', $recurrence->city_id)
                ->where('month', $recurrence->month)->where('day', $recurrence->day)
                ->when($recurrence->exists, fn ($query) => $query->whereKeyNot($recurrence->id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Essa recorrência já está cadastrada para a cidade.']);
            }
        });
        static::forceDeleting(function (): never {
            throw ValidationException::withMessages(['recurrence' => 'Recorrências não podem ser excluídas fisicamente.']);
        });
    }
}
