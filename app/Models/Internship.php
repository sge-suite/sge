<?php

namespace App\Models;

use App\Enums\AffiliationType;
use App\Enums\InternshipStatus;
use Database\Factories\InternshipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as LaravelValidator;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'student_affiliation_id', 'advisor_affiliation_id', 'supervisor_affiliation_id',
    'student_address_id', 'course_id', 'internship_type_id', 'granting_party_id',
    'workplace_address_id', 'student_snapshot', 'internship_type_snapshot',
    'granting_party_snapshot', 'supervisor_snapshot', 'weekly_hours', 'activities',
    'internship_sector', 'planned_start_date', 'projected_end_date', 'released_at',
    'released_by_affiliation_id', 'is_remunerated', 'grant_value',
    'transportation_allowance', 'protocol_number', 'observations', 'supervisor_grade',
    'report_grade', 'presentation_grade', 'report_graded_by_affiliation_id',
    'presentation_graded_by_affiliation_id', 'report_graded_at',
    'presentation_graded_at', 'consolidated_grade', 'status',
    'evaluation_released_at', 'evaluation_released_by_affiliation_id',
    'current_supervisor_evaluation_id',
])]
class Internship extends Model
{
    /** @use HasFactory<InternshipFactory> */
    use HasFactory;

    use LogsActivity;

    protected $attributes = [
        'is_remunerated' => false,
        'status' => 'pending_formalization',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'student_affiliation_id' => 'integer',
            'advisor_affiliation_id' => 'integer',
            'supervisor_affiliation_id' => 'integer',
            'student_address_id' => 'integer',
            'course_id' => 'integer',
            'internship_type_id' => 'integer',
            'granting_party_id' => 'integer',
            'workplace_address_id' => 'integer',
            'student_snapshot' => 'array',
            'internship_type_snapshot' => 'array',
            'granting_party_snapshot' => 'array',
            'supervisor_snapshot' => 'array',
            'weekly_hours' => 'array',
            'planned_start_date' => 'date',
            'projected_end_date' => 'date',
            'released_at' => 'datetime',
            'released_by_affiliation_id' => 'integer',
            'is_remunerated' => 'boolean',
            'grant_value' => 'decimal:2',
            'transportation_allowance' => 'decimal:2',
            'supervisor_grade' => 'decimal:1',
            'report_grade' => 'decimal:1',
            'presentation_grade' => 'decimal:1',
            'report_graded_by_affiliation_id' => 'integer',
            'presentation_graded_by_affiliation_id' => 'integer',
            'report_graded_at' => 'datetime',
            'presentation_graded_at' => 'datetime',
            'consolidated_grade' => 'decimal:1',
            'status' => InternshipStatus::class,
            'evaluation_released_at' => 'datetime',
            'evaluation_released_by_affiliation_id' => 'integer',
            'current_supervisor_evaluation_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function studentAffiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'student_affiliation_id');
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function advisorAffiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'advisor_affiliation_id');
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function supervisorAffiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'supervisor_affiliation_id');
    }

    /** @return HasMany<SupervisorEvaluation, $this> */
    public function supervisorEvaluations(): HasMany
    {
        return $this->hasMany(SupervisorEvaluation::class);
    }

    /** @return HasMany<GeneratedDocument, $this> */
    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    /** @return HasMany<InternshipPause, $this> */
    public function pauses(): HasMany
    {
        return $this->hasMany(InternshipPause::class);
    }

    /** @return HasMany<InternshipCancellationRequest, $this> */
    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(InternshipCancellationRequest::class);
    }

    /** @return HasMany<InternshipCalendarOverride, $this> */
    public function calendarOverrides(): HasMany
    {
        return $this->hasMany(InternshipCalendarOverride::class);
    }

    /** @return HasMany<InternshipWorkSchedule, $this> */
    public function workSchedules(): HasMany
    {
        return $this->hasMany(InternshipWorkSchedule::class);
    }

    /** @return HasOne<InternshipRequest, $this> */
    public function internshipRequest(): HasOne
    {
        return $this->hasOne(InternshipRequest::class);
    }

    /** @return BelongsTo<SupervisorEvaluation, $this> */
    public function currentSupervisorEvaluation(): BelongsTo
    {
        return $this->belongsTo(SupervisorEvaluation::class, 'current_supervisor_evaluation_id');
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function evaluationReleasedByAffiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'evaluation_released_by_affiliation_id');
    }

    /** @return BelongsTo<Address, $this> */
    public function studentAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'student_address_id');
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<InternshipType, $this> */
    public function internshipType(): BelongsTo
    {
        return $this->belongsTo(InternshipType::class);
    }

    /** @return BelongsTo<GrantingParty, $this> */
    public function grantingParty(): BelongsTo
    {
        return $this->belongsTo(GrantingParty::class);
    }

    /** @return BelongsTo<Address, $this> */
    public function workplaceAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'workplace_address_id');
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function releasedByAffiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'released_by_affiliation_id');
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function reportGradedByAffiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'report_graded_by_affiliation_id');
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function presentationGradedByAffiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'presentation_graded_by_affiliation_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    protected static function booted(): void
    {
        static::saving(function (self $internship): void {
            $attributes = $internship->getAttributes();
            $assignsStudent = ! $internship->exists || $internship->isDirty(['student_affiliation_id', 'course_id']);
            $assignsType = ! $internship->exists || $internship->isDirty(['internship_type_id', 'course_id']);
            $studentExists = Rule::exists(Affiliation::class, 'id');
            $typeExists = Rule::exists(InternshipType::class, 'id');

            if ($assignsStudent) {
                $studentExists->where('type', AffiliationType::Student->value)
                    ->where('course_id', $attributes['course_id'] ?? null);
            }

            if ($assignsType) {
                $typeExists->where('course_id', $attributes['course_id'] ?? null);
            }

            $data = [
                ...$attributes,
                'student_snapshot' => $internship->student_snapshot,
                'internship_type_snapshot' => $internship->internship_type_snapshot,
                'granting_party_snapshot' => $internship->granting_party_snapshot,
                'supervisor_snapshot' => $internship->supervisor_snapshot,
                'weekly_hours' => $internship->weekly_hours,
            ];

            $validator = Validator::make($data, [
                'student_affiliation_id' => ['required', 'integer', $studentExists],
                'advisor_affiliation_id' => ['required', 'integer', Rule::exists(Affiliation::class, 'id')->where('type', AffiliationType::Advisor->value)],
                'supervisor_affiliation_id' => ['required', 'integer', Rule::exists(Affiliation::class, 'id')->where('type', AffiliationType::Supervisor->value)],
                'student_address_id' => ['nullable', 'integer', Rule::exists(Address::class, 'id')],
                'course_id' => ['required', 'integer', Rule::exists(Course::class, 'id')],
                'internship_type_id' => ['required', 'integer', $typeExists],
                'granting_party_id' => ['required', 'integer', Rule::exists(GrantingParty::class, 'id')],
                'workplace_address_id' => ['required', 'integer', Rule::exists(Address::class, 'id')],
                'student_snapshot' => ['required', 'array'],
                'internship_type_snapshot' => ['required', 'array'],
                'internship_type_snapshot.rules.required_hours' => ['required', 'integer', 'min:1'],
                'internship_type_snapshot.rules.max_daily_hours' => ['required', 'integer', 'min:6'],
                'internship_type_snapshot.rules.max_weekly_hours' => ['required', 'integer', 'min:30'],
                'granting_party_snapshot' => ['required', 'array'],
                'supervisor_snapshot' => ['required', 'array'],
                'weekly_hours' => ['required', 'array'],
                'activities' => ['required', 'string'],
                'internship_sector' => ['nullable', 'string', 'max:255'],
                'planned_start_date' => ['required', 'date'],
                'projected_end_date' => ['required', 'date', 'after_or_equal:planned_start_date'],
                'released_at' => ['nullable', 'date', 'required_with:released_by_affiliation_id'],
                'released_by_affiliation_id' => ['nullable', 'integer', Rule::exists(Affiliation::class, 'id')->where('type', AffiliationType::InternshipOffice->value), 'required_with:released_at'],
                'is_remunerated' => ['required', 'boolean'],
                'grant_value' => [Rule::requiredIf($internship->is_remunerated), 'nullable', 'numeric', 'gt:0'],
                'transportation_allowance' => ['nullable', 'numeric', 'min:0'],
                'protocol_number' => ['nullable', 'string', 'max:255'],
                'observations' => ['nullable', 'string'],
                'supervisor_grade' => ['nullable', 'numeric', 'min:0'],
                'report_grade' => ['nullable', 'numeric', 'min:0'],
                'presentation_grade' => ['nullable', 'numeric', 'min:0'],
                'report_graded_by_affiliation_id' => ['nullable', 'integer', Rule::exists(Affiliation::class, 'id')->where('type', AffiliationType::Advisor->value)],
                'presentation_graded_by_affiliation_id' => ['nullable', 'integer', Rule::exists(Affiliation::class, 'id')->where('type', AffiliationType::Advisor->value)],
                'report_graded_at' => ['nullable', 'date'],
                'presentation_graded_at' => ['nullable', 'date'],
                'consolidated_grade' => ['nullable', 'numeric', 'min:0'],
                'status' => ['required', Rule::enum(InternshipStatus::class)],
                'evaluation_released_at' => ['nullable', 'date'],
                'evaluation_released_by_affiliation_id' => ['nullable', 'integer', Rule::exists(Affiliation::class, 'id')->where('type', AffiliationType::InternshipOffice->value)],
                'current_supervisor_evaluation_id' => ['nullable', 'integer', Rule::exists(SupervisorEvaluation::class, 'id')->where('internship_id', $internship->id)->where('status', 'approved')],
            ]);

            $validator->after(function (LaravelValidator $validator) use ($internship): void {
                $hours = $internship->weekly_hours;
                $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

                if (! is_array($hours) || array_diff(array_keys($hours), $days) !== [] || array_diff($days, array_keys($hours)) !== []) {
                    $validator->errors()->add('weekly_hours', 'A jornada deve informar os sete dias da semana.');

                    return;
                }

                $rules = $internship->internship_type_snapshot['rules'] ?? null;
                if (! is_array($rules) || ! is_int($rules['max_daily_hours'] ?? null) || ! is_int($rules['max_weekly_hours'] ?? null)) {
                    return;
                }

                $total = 0;
                foreach ($hours as $hoursInDay) {
                    if (! is_int($hoursInDay) || $hoursInDay < 0 || $hoursInDay > $rules['max_daily_hours']) {
                        $validator->errors()->add('weekly_hours', 'A jornada diária deve ser inteira e respeitar o limite do tipo de estágio.');

                        return;
                    }

                    $total += $hoursInDay;
                }

                if ($total === 0 || $total > $rules['max_weekly_hours']) {
                    $validator->errors()->add('weekly_hours', 'A carga semanal deve ser positiva e respeitar o limite do tipo de estágio.');
                }
            });

            $validator->validate();
        });
    }
}
