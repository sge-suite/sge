<?php

namespace App\Models;

use App\Concerns\InternshipTypeValidationRules;
use Database\Factories\InternshipTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $course_id
 * @property string $name
 * @property list<string> $allowed_academic_periods
 * @property int $required_hours
 * @property int $supervisor_evaluation_weight
 * @property int $report_weight
 * @property int $presentation_weight
 * @property numeric-string $very_good_value
 * @property numeric-string $good_value
 * @property numeric-string $satisfactory_value
 * @property numeric-string $unsatisfactory_value
 * @property int $max_daily_hours
 * @property int $max_weekly_hours
 * @property int $safety_margin_days
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Course $course
 */
#[Fillable([
    'course_id',
    'name',
    'allowed_academic_periods',
    'required_hours',
    'supervisor_evaluation_weight',
    'report_weight',
    'presentation_weight',
    'very_good_value',
    'good_value',
    'satisfactory_value',
    'unsatisfactory_value',
    'max_daily_hours',
    'max_weekly_hours',
    'safety_margin_days',
    'deactivated_at',
])]
class InternshipType extends Model
{
    /** @use HasFactory<InternshipTypeFactory> */
    use HasFactory;

    use InternshipTypeValidationRules;
    use LogsActivity;

    protected $attributes = [
        'max_daily_hours' => 6,
        'max_weekly_hours' => 30,
        'safety_margin_days' => 7,
    ];

    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'allowed_academic_periods' => 'array',
            'required_hours' => 'integer',
            'supervisor_evaluation_weight' => 'integer',
            'report_weight' => 'integer',
            'presentation_weight' => 'integer',
            'very_good_value' => 'decimal:1',
            'good_value' => 'decimal:1',
            'satisfactory_value' => 'decimal:1',
            'unsatisfactory_value' => 'decimal:1',
            'max_daily_hours' => 'integer',
            'max_weekly_hours' => 'integer',
            'safety_margin_days' => 'integer',
            'deactivated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<Internship, $this> */
    public function internships(): HasMany
    {
        return $this->hasMany(Internship::class);
    }

    /** @return HasMany<InternshipRequest, $this> */
    public function internshipRequests(): HasMany
    {
        return $this->hasMany(InternshipRequest::class);
    }

    /** @return array<string, string> */
    public function academicPeriodOptions(): array
    {
        $periods = $this->allowed_academic_periods ?? [];

        return array_combine($periods, $periods);
    }

    public function hasLinkedRecords(): bool
    {
        return $this->internships()->exists() || $this->internshipRequests()->exists();
    }

    /**
     * @param  Builder<InternshipType>  $query
     * @return Builder<InternshipType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deactivated_at');
    }

    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        $activity->properties = ($activity->properties ?? collect())->put('campus_id',
            Course::query()->whereKey($this->course_id)->value('campus_id'));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    protected static function booted(): void
    {
        static::saving(function (self $internshipType): void {
            $attributes = $internshipType->getAttributes();
            $attributes['allowed_academic_periods'] = $internshipType->allowed_academic_periods;
            $requiresActiveCourse = ! $internshipType->exists
                || (int) ($attributes['course_id'] ?? null) !== (int) $internshipType->getOriginal('course_id')
                || ($internshipType->getOriginal('deactivated_at') !== null && ($attributes['deactivated_at'] ?? null) === null);
            $validator = Validator::make(
                $attributes,
                $internshipType->internshipTypeRules($requiresActiveCourse),
                $internshipType->internshipTypeValidationMessages(),
                $internshipType->internshipTypeValidationAttributes(),
            );

            $validator->after(function (LaravelValidator $validator) use ($internshipType, $attributes): void {
                $internshipType->validateInternshipTypeValues($validator, $attributes);
            });

            $validator->validate();
        });
    }
}
