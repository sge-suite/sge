<?php

namespace App\Models;

use App\Casts\CpfCast;
use App\Casts\PhoneCast;
use App\Concerns\InternshipRequestValidationRules;
use App\Concerns\SupervisorProfessionalDataValidationRules;
use App\Enums\AffiliationType;
use App\Enums\EducationLevel;
use App\Enums\RegistrationRequestStatus;
use Database\Factories\SupervisorRegistrationRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property RegistrationRequestStatus $status
 * @property bool|null $has_academic_background
 * @property EducationLevel|null $education_level
 * @property int|null $supervisor_affiliation_id
 */
#[Fillable([
    'name', 'cpf', 'phone', 'email', 'job_role', 'has_academic_background',
    'training_course', 'education_level', 'experience_time', 'status',
    'supervisor_affiliation_id', 'reviewed_at', 'decision_reason',
])]
#[Hidden(['cpf'])]
class SupervisorRegistrationRequest extends Model
{
    /** @use HasFactory<SupervisorRegistrationRequestFactory> */
    use HasFactory;

    use InternshipRequestValidationRules;
    use LogsActivity;
    use SupervisorProfessionalDataValidationRules;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'phone' => PhoneCast::class,
            'cpf' => CpfCast::class,
            'status' => RegistrationRequestStatus::class,
            'has_academic_background' => 'boolean',
            'education_level' => EducationLevel::class,
            'supervisor_affiliation_id' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function supervisorAffiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'supervisor_affiliation_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $registrationRequest): void {
            foreach ([
                'name', 'cpf', 'phone', 'email', 'job_role', 'training_course',
                'experience_time', 'decision_reason',
            ] as $attribute) {
                $value = $registrationRequest->getAttribute($attribute);

                if (is_string($value)) {
                    $value = trim($value);
                    $registrationRequest->{$attribute} = $value === '' ? null : $value;
                }
            }

            $statusValue = $registrationRequest->getAttributes()['status'] ?? null;
            $status = is_string($statusValue) ? RegistrationRequestStatus::tryFrom($statusValue) : null;
            $isDraft = $status === RegistrationRequestStatus::Draft;
            $isApproved = $status === RegistrationRequestStatus::Approved;
            $requiresDecisionReason = in_array($status, [
                RegistrationRequestStatus::Rejected,
                RegistrationRequestStatus::Cancelled,
            ], true);
            $requiredWhenSubmitted = $isDraft ? 'nullable' : 'required';

            $attributes = $registrationRequest->getAttributes();
            Validator::make($attributes, [
                'name' => [$requiredWhenSubmitted, 'string', 'max:255'],
                'cpf' => [$requiredWhenSubmitted],
                'phone' => [$requiredWhenSubmitted, 'string', 'regex:/^\d{10,11}$/'],
                'email' => [$requiredWhenSubmitted, 'string', 'email', 'max:254'],
                ...$registrationRequest->supervisorQualificationRules($isDraft, $registrationRequest->has_academic_background),
                'status' => ['required', Rule::enum(RegistrationRequestStatus::class)],
                'supervisor_affiliation_id' => [
                    'bail',
                    Rule::requiredIf($isApproved),
                    'nullable',
                    'integer',
                    Rule::exists('affiliations', 'id')->where('type', AffiliationType::Supervisor->value),
                ],
                'reviewed_at' => ['nullable', 'date'],
                'decision_reason' => [
                    Rule::requiredIf($requiresDecisionReason), 'nullable', 'string',
                ],
            ], attributes: $registrationRequest->internshipFieldLabels())->validate();
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages([
                'supervisor_registration_request' => 'Solicitações de cadastro não podem ser excluídas fisicamente.',
            ]);
        });
    }
}
