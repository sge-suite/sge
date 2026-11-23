<?php

namespace App\Models;

use App\Enums\RegistrationRequestStatus;
use Carbon\CarbonImmutable;
use Database\Factories\HolidayRegistrationRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $campus_id
 * @property int $requested_by_affiliation_id
 * @property int|null $reviewed_by_affiliation_id
 * @property string $kind
 * @property string $name
 * @property int $city_id
 * @property CarbonImmutable|null $date
 * @property int|null $month
 * @property int|null $day
 * @property string|null $notes
 * @property RegistrationRequestStatus $status
 * @property string|null $decision_notes
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $holiday_id
 * @property int|null $holiday_recurrence_id
 */
#[Fillable([
    'campus_id',
    'requested_by_affiliation_id',
    'reviewed_by_affiliation_id',
    'kind',
    'name',
    'city_id',
    'date',
    'month',
    'day',
    'notes',
    'status',
    'decision_notes',
    'reviewed_at',
    'holiday_id',
    'holiday_recurrence_id',
])]
class HolidayRegistrationRequest extends Model
{
    /** @use HasFactory<HolidayRegistrationRequestFactory> */
    use HasFactory;

    use LogsActivity;

    protected $attributes = [
        'kind' => 'holiday',
        'status' => 'submitted',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'campus_id' => 'integer',
            'requested_by_affiliation_id' => 'integer',
            'reviewed_by_affiliation_id' => 'integer',
            'city_id' => 'integer',
            'date' => 'immutable_date',
            'month' => 'integer',
            'day' => 'integer',
            'status' => RegistrationRequestStatus::class,
            'reviewed_at' => 'immutable_datetime',
            'holiday_id' => 'integer',
            'holiday_recurrence_id' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function requestedByAffiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'requested_by_affiliation_id');
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function reviewedByAffiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'reviewed_by_affiliation_id');
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<Holiday, $this> */
    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class)->withTrashed();
    }

    /** @return BelongsTo<HolidayRecurrence, $this> */
    public function holidayRecurrence(): BelongsTo
    {
        return $this->belongsTo(HolidayRecurrence::class, 'holiday_recurrence_id')->withTrashed();
    }

    public function kindLabel(): string
    {
        return $this->kind === 'recurrence' ? 'Recorrência municipal anual' : 'Data específica';
    }

    public function periodSummary(): string
    {
        if ($this->kind === 'recurrence') {
            return sprintf('%02d/%02d (todo ano)', (int) $this->day, (int) $this->month);
        }

        return $this->date !== null ? formatShort($this->date) : '—';
    }
}
