<?php

namespace App\Models;

use App\Concerns\SupervisorProfessionalDataValidationRules;
use App\Enums\AffiliationType;
use App\Enums\EducationLevel;
use App\Enums\EvaluationConcept;
use App\Enums\EvaluationStatus;
use Database\Factories\SupervisorEvaluationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property EvaluationStatus $status
 * @property EducationLevel|null $education_level
 */
#[Fillable([
    'internship_id', 'supervisor_affiliation_id', 'status',
    'has_academic_background', 'training_course', 'education_level', 'job_role',
    'experience_time', 'hours_requirement_met', 'estimated_hours_remaining',
    'performance', 'comprehension', 'technical_knowledge', 'organization',
    'initiative', 'attendance', 'discipline', 'sociability', 'cooperation',
    'responsibility', 'considerations', 'suggestions_to_institution',
    'performance_issues', 'other_observations', 'submitted_at', 'reviewed_at',
    'review_notes', 'cancelled_at', 'cancellation_reason',
])]
class SupervisorEvaluation extends Model
{
    /** @use HasFactory<SupervisorEvaluationFactory> */
    use HasFactory;

    use LogsActivity;
    use SupervisorProfessionalDataValidationRules;

    private const array CRITERIA = [
        'performance', 'comprehension', 'technical_knowledge', 'organization',
        'initiative', 'attendance', 'discipline', 'sociability', 'cooperation',
        'responsibility',
    ];

    /** @return array<string, string> */
    public static function criteria(): array
    {
        return array_combine(self::CRITERIA, [
            'Desempenho', 'Compreensão', 'Conhecimento técnico', 'Organização',
            'Iniciativa', 'Assiduidade', 'Disciplina', 'Sociabilidade', 'Cooperação', 'Responsabilidade',
        ]);
    }

    /** @return array<string, string> */
    public static function comments(): array
    {
        return ['considerations' => 'Considerações', 'suggestions_to_institution' => 'Sugestões à instituição',
            'performance_issues' => 'Aspectos que prejudicaram o desempenho', 'other_observations' => 'Outras observações'];
    }

    private const array ANSWER_FIELDS = [
        'internship_id', 'supervisor_affiliation_id', 'has_academic_background',
        'training_course', 'education_level', 'job_role', 'experience_time',
        'hours_requirement_met', 'estimated_hours_remaining', 'performance',
        'comprehension', 'technical_knowledge', 'organization', 'initiative',
        'attendance', 'discipline', 'sociability', 'cooperation', 'responsibility',
        'considerations', 'suggestions_to_institution', 'performance_issues',
        'other_observations', 'submitted_at',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'internship_id' => 'integer',
            'supervisor_affiliation_id' => 'integer',
            'status' => EvaluationStatus::class,
            'has_academic_background' => 'boolean',
            'education_level' => EducationLevel::class,
            'hours_requirement_met' => 'boolean',
            'estimated_hours_remaining' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Internship, $this> */
    public function internship(): BelongsTo
    {
        return $this->belongsTo(Internship::class);
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function supervisorAffiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'supervisor_affiliation_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    protected static function booted(): void
    {
        static::saving(function (self $evaluation): void {
            $status = $evaluation->status;
            $isDraft = $status === EvaluationStatus::Draft;
            $isPartial = in_array($status, [EvaluationStatus::Draft, EvaluationStatus::Returned, EvaluationStatus::Cancelled], true);
            $hasCompletedHours = $evaluation->hours_requirement_met === true;
            $internship = Internship::find($evaluation->internship_id);
            $needsReview = in_array($status, [EvaluationStatus::Returned, EvaluationStatus::Approved], true);

            foreach ([
                'training_course', 'job_role', 'experience_time',
                'considerations', 'suggestions_to_institution', 'performance_issues',
                'other_observations', 'review_notes', 'cancellation_reason',
            ] as $attribute) {
                if (is_string($evaluation->{$attribute})) {
                    $evaluation->{$attribute} = trim($evaluation->{$attribute}) ?: null;
                }
            }

            $required = $isPartial ? 'nullable' : 'required';
            $rules = [
                'internship_id' => ['required', 'integer', Rule::exists(Internship::class, 'id')],
                'supervisor_affiliation_id' => ['required', 'integer', Rule::exists(Affiliation::class, 'id')->where('type', AffiliationType::Supervisor->value)],
                'status' => ['required', Rule::enum(EvaluationStatus::class)],
                ...$evaluation->supervisorQualificationRules($isPartial || ! $hasCompletedHours, $evaluation->has_academic_background),
                'hours_requirement_met' => [$required, 'boolean'],
                'estimated_hours_remaining' => [Rule::requiredIf(! $isPartial && $evaluation->hours_requirement_met === false), Rule::prohibitedIf($hasCompletedHours), 'nullable', 'integer', 'min:1', 'max:'.min(65535, (int) ($internship?->internship_type_snapshot['rules']['required_hours'] ?? 0))],
                'submitted_at' => [$isDraft || ($status === EvaluationStatus::Cancelled && $evaluation->submitted_at === null) ? 'nullable' : 'required', 'date'],
                'reviewed_at' => [$needsReview ? 'required' : 'nullable', 'date'],
                'review_notes' => [$status === EvaluationStatus::Returned ? 'required' : 'nullable', 'string'],
                'cancelled_at' => [$status === EvaluationStatus::Cancelled ? 'required' : 'nullable', 'date'],
                'cancellation_reason' => [$status === EvaluationStatus::Cancelled ? 'required' : 'nullable', 'string'],
            ];

            foreach (self::CRITERIA as $criterion) {
                $rules[$criterion] = [$hasCompletedHours ? $required : 'prohibited', 'nullable', Rule::in(EvaluationConcept::values())];
            }

            foreach (['considerations', 'suggestions_to_institution', 'performance_issues', 'other_observations'] as $comment) {
                $rules[$comment] = [$hasCompletedHours ? 'nullable' : 'prohibited', 'nullable', 'string', 'max:5000'];
            }

            if (! $hasCompletedHours) {
                foreach (['has_academic_background', 'job_role', 'training_course', 'education_level', 'experience_time'] as $field) {
                    $rules[$field] = ['prohibited'];
                }
            }

            $validator = Validator::make($evaluation->getAttributes(), $rules);
            $validator->after(function (LaravelValidator $validator) use ($evaluation, $status): void {
                if ($status === EvaluationStatus::Approved && $evaluation->hours_requirement_met !== true) {
                    $validator->errors()->add('hours_requirement_met', 'A carga horária deve estar cumprida para aprovar a avaliação.');
                }

                if (! $evaluation->exists) {
                    $internship = Internship::find($evaluation->internship_id);
                    if ($internship && $internship->supervisor_affiliation_id !== $evaluation->supervisor_affiliation_id) {
                        $validator->errors()->add('supervisor_affiliation_id', 'O supervisor deve pertencer ao estágio.');
                    }
                }

                if (! $evaluation->exists || ! $evaluation->isDirty('status')) {
                    return;
                }

                $previousStatus = EvaluationStatus::tryFrom($evaluation->getRawOriginal('status'));
                $transitions = [
                    'draft' => [EvaluationStatus::Submitted, EvaluationStatus::Cancelled],
                    'submitted' => [EvaluationStatus::Returned, EvaluationStatus::Approved, EvaluationStatus::Cancelled],
                    'returned' => [EvaluationStatus::Submitted, EvaluationStatus::Cancelled],
                    'approved' => [],
                    'cancelled' => [],
                ];

                if ($previousStatus && ! in_array($status, $transitions[$previousStatus->value], true)) {
                    $validator->errors()->add('status', 'Transição de avaliação inválida.');
                }

                if ($previousStatus === EvaluationStatus::Returned && $status === EvaluationStatus::Submitted && ! $evaluation->isDirty('submitted_at')) {
                    $validator->errors()->add('submitted_at', 'Informe o momento do novo envio.');
                }
            });

            $validator->validate();

            if ($evaluation->exists) {
                $previousStatus = EvaluationStatus::tryFrom($evaluation->getRawOriginal('status'));

                if (in_array($previousStatus, [EvaluationStatus::Approved, EvaluationStatus::Cancelled], true) && $evaluation->isDirty()) {
                    throw ValidationException::withMessages(['status' => 'Avaliações aprovadas ou canceladas não podem ser alteradas.']);
                }

                if ($previousStatus === EvaluationStatus::Submitted && $evaluation->isDirty(self::ANSWER_FIELDS)) {
                    throw ValidationException::withMessages(['answer' => 'A resposta enviada não pode ser alterada.']);
                }
            }
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages([
                'supervisor_evaluation' => 'Avaliações não podem ser excluídas fisicamente.',
            ]);
        });
    }
}
