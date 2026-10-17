<?php

namespace App\Models;

use App\Concerns\SupervisorProfessionalDataValidationRules;
use App\Enums\EducationLevel;
use App\Enums\SupervisorQualificationType;
use Database\Factories\SupervisorQualificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $user_id
 * @property SupervisorQualificationType $type
 * @property EducationLevel|null $education_level
 * @property string|null $training_course
 * @property string|null $experience_area
 * @property string|null $experience_time
 */
#[Fillable(['user_id', 'type', 'training_course', 'education_level', 'experience_area', 'experience_time'])]
class SupervisorQualification extends Model
{
    /** @use HasFactory<SupervisorQualificationFactory> */
    use HasFactory;

    use LogsActivity;
    use SupervisorProfessionalDataValidationRules;

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'type' => SupervisorQualificationType::class, 'education_level' => EducationLevel::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    public function label(): string
    {
        return $this->type === SupervisorQualificationType::Academic
            ? $this->education_level->label().' · '.$this->training_course
            : $this->experience_area.' · '.$this->experience_time;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return ['source_qualification_id' => $this->id,
            'has_academic_background' => $this->type === SupervisorQualificationType::Academic,
            'training_course' => $this->training_course, 'education_level' => $this->education_level?->value,
            'experience_area' => $this->experience_area, 'experience_time' => $this->experience_time];
    }

    protected static function booted(): void
    {
        static::saving(function (self $qualification): void {
            $data = Arr::only($qualification->getAttributes(), ['type', 'training_course', 'education_level', 'experience_area', 'experience_time']);
            Validator::make(['qualifications' => [$data]], $qualification->supervisorProfileQualificationRules())->validate();
            $academic = $qualification->type === SupervisorQualificationType::Academic;
            foreach ($academic ? ['experience_area', 'experience_time'] : ['training_course', 'education_level'] as $field) {
                $qualification->{$field} = null;
            }
        });
    }
}
