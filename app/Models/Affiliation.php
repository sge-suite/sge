<?php

namespace App\Models;

use App\Concerns\AffiliationValidationRules;
use App\Enums\AffiliationType;
use Database\Factories\AffiliationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $campus_id
 * @property int|null $course_id
 * @property AffiliationType $type
 * @property string|null $registration_number
 * @property string $email
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Campus|null $campus
 */
#[Fillable(['user_id', 'campus_id', 'course_id', 'type', 'registration_number', 'email', 'deactivated_at'])]
class Affiliation extends Model
{
    use AffiliationValidationRules;

    /** @use HasFactory<AffiliationFactory> */
    use HasFactory;

    use LogsActivity;
    use Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'campus_id' => 'integer',
            'course_id' => 'integer',
            'type' => AffiliationType::class,
            'registration_number' => 'string',
            'email' => 'string',
            'deactivated_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<Course, $this> */
    public function primaryCoordinatedCourses(): HasMany
    {
        return $this->hasMany(Course::class, 'primary_coordinator_affiliation_id');
    }

    /** @return HasMany<Course, $this> */
    public function secondaryCoordinatedCourses(): HasMany
    {
        return $this->hasMany(Course::class, 'secondary_coordinator_affiliation_id');
    }

    /** @return HasMany<SupervisorRegistrationRequest, $this> */
    public function supervisorRegistrationRequests(): HasMany
    {
        return $this->hasMany(SupervisorRegistrationRequest::class, 'supervisor_affiliation_id');
    }

    /** @return HasMany<SupervisorEvaluation, $this> */
    public function supervisorEvaluations(): HasMany
    {
        return $this->hasMany(SupervisorEvaluation::class, 'supervisor_affiliation_id');
    }

    /** @return HasMany<InternshipRequest, $this> */
    public function internshipRequests(): HasMany
    {
        return $this->hasMany(InternshipRequest::class);
    }

    /**
     * @param  Builder<Affiliation>  $query
     * @return Builder<Affiliation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deactivated_at');
    }

    /**
     * @param  Builder<Affiliation>  $query
     * @return Builder<Affiliation>
     */
    public function scopeOrderByLastUsedAt(Builder $query): Builder
    {
        return $query->orderByRaw('last_used_at DESC NULLS LAST')->orderBy('id');
    }

    public function markAsUsed(): bool
    {
        if (! $this->exists || $this->deactivated_at !== null) {
            return false;
        }

        $this->last_used_at = now();

        return $this->save();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnly(['last_used_at'])
            ->logOnlyDirty();
    }

    protected static function booted(): void
    {
        static::saving(function (self $affiliation): void {
            $affiliation->nullifyBlankOptionalAffiliationValues();

            Validator::make($affiliation->getAttributes(), $affiliation->affiliationRules())->validate();
        });
    }
}
