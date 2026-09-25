<?php

namespace App\Models;

use App\Enums\AffiliationType;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $campus_id
 * @property string $name
 * @property int|null $primary_coordinator_affiliation_id
 * @property int|null $secondary_coordinator_affiliation_id
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['campus_id', 'name', 'primary_coordinator_affiliation_id', 'secondary_coordinator_affiliation_id', 'deactivated_at'])]
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    use LogsActivity;

    protected function casts(): array
    {
        return [
            'campus_id' => 'integer',
            'primary_coordinator_affiliation_id' => 'integer',
            'secondary_coordinator_affiliation_id' => 'integer',
            'deactivated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function primaryCoordinator(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'primary_coordinator_affiliation_id');
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function secondaryCoordinator(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'secondary_coordinator_affiliation_id');
    }

    /** @return HasMany<Affiliation, $this> */
    public function studentAffiliations(): HasMany
    {
        return $this->hasMany(Affiliation::class);
    }

    /** @return HasMany<InternshipType, $this> */
    public function internshipTypes(): HasMany
    {
        return $this->hasMany(InternshipType::class);
    }

    /**
     * @param  Builder<Course>  $query
     * @return Builder<Course>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deactivated_at');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    protected static function booted(): void
    {
        static::saving(function (self $course): void {
            foreach (['primary_coordinator_affiliation_id', 'secondary_coordinator_affiliation_id', 'deactivated_at'] as $attribute) {
                if (blank($course->getAttributes()[$attribute] ?? null)) {
                    $course->{$attribute} = null;
                }
            }

            $attributes = $course->getAttributes();
            $campusId = $attributes['campus_id'] ?? null;
            $requiresActiveCampus = ! $course->exists
                || (int) $campusId !== (int) $course->getOriginal('campus_id')
                || ($course->getOriginal('deactivated_at') !== null && ($attributes['deactivated_at'] ?? null) === null);
            $campusExists = $requiresActiveCampus
                ? Rule::exists('campuses', 'id')->whereNull('deactivated_at')->whereNull('deleted_at')
                : Rule::exists('campuses', 'id');

            $coordinatorRules = function (string $attribute) use ($course, $campusId): array {
                $needsActiveCoordinator = ! $course->exists || $course->isDirty($attribute);
                $exists = Rule::exists('affiliations', 'id')
                    ->where('type', AffiliationType::Coordinator->value)
                    ->where('campus_id', $campusId);

                if ($needsActiveCoordinator) {
                    $exists->whereNull('deactivated_at');
                }

                return ['bail', 'nullable', 'integer', $exists];
            };

            Validator::make($attributes, [
                'campus_id' => ['bail', 'required', 'integer', $campusExists],
                'name' => ['required', 'string', 'max:255'],
                'primary_coordinator_affiliation_id' => $coordinatorRules('primary_coordinator_affiliation_id'),
                'secondary_coordinator_affiliation_id' => [
                    ...$coordinatorRules('secondary_coordinator_affiliation_id'),
                    'different:primary_coordinator_affiliation_id',
                ],
                'deactivated_at' => ['nullable', 'date'],
            ])->validate();
        });
    }
}
