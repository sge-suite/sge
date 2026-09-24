<?php

namespace App\Models;

use App\Casts\CpfCast;
use App\Enums\AffiliationType;
use App\Enums\EmancipationEvidenceStatus;
use App\Enums\InternshipRequestStatus;
use App\Enums\LegalCapacityDeclaration;
use Database\Factories\InternshipRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'affiliation_id', 'course_id', 'internship_type_id', 'advisor_affiliation_id',
    'granting_party_id', 'granting_party_registration_request_id',
    'supervisor_affiliation_id', 'supervisor_registration_request_id',
    'student_year_semester', 'legal_capacity_declaration', 'legal_guardian_name',
    'legal_guardian_cpf', 'legal_guardian_kinship', 'legal_guardian_email',
    'activities', 'internship_sector', 'weekly_hours', 'planned_start_date',
    'projected_end_date', 'is_remunerated', 'grant_value',
    'transportation_allowance', 'observations', 'status', 'internship_id',
    'terms_accepted_at',
])]
#[Hidden(['legal_guardian_cpf'])]
class InternshipRequest extends Model
{
    /** @use HasFactory<InternshipRequestFactory> */
    use HasFactory;

    use LogsActivity;

    protected $attributes = [
        'status' => 'draft',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'affiliation_id' => 'integer',
            'course_id' => 'integer',
            'internship_type_id' => 'integer',
            'advisor_affiliation_id' => 'integer',
            'granting_party_id' => 'integer',
            'granting_party_registration_request_id' => 'integer',
            'supervisor_affiliation_id' => 'integer',
            'supervisor_registration_request_id' => 'integer',
            'legal_capacity_declaration' => LegalCapacityDeclaration::class,
            'legal_guardian_cpf' => CpfCast::class,
            'weekly_hours' => 'array',
            'planned_start_date' => 'date',
            'projected_end_date' => 'date',
            'is_remunerated' => 'boolean',
            'grant_value' => 'decimal:2',
            'transportation_allowance' => 'decimal:2',
            'status' => InternshipRequestStatus::class,
            'internship_id' => 'integer',
            'terms_accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function affiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class);
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

    /** @return BelongsTo<Affiliation, $this> */
    public function advisorAffiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'advisor_affiliation_id');
    }

    /** @return BelongsTo<GrantingParty, $this> */
    public function grantingParty(): BelongsTo
    {
        return $this->belongsTo(GrantingParty::class);
    }

    /** @return BelongsTo<GrantingPartyRegistrationRequest, $this> */
    public function grantingPartyRegistrationRequest(): BelongsTo
    {
        return $this->belongsTo(GrantingPartyRegistrationRequest::class);
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function supervisorAffiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'supervisor_affiliation_id');
    }

    /** @return BelongsTo<SupervisorRegistrationRequest, $this> */
    public function supervisorRegistrationRequest(): BelongsTo
    {
        return $this->belongsTo(SupervisorRegistrationRequest::class);
    }

    /** @return BelongsTo<Internship, $this> */
    public function internship(): BelongsTo
    {
        return $this->belongsTo(Internship::class);
    }

    /** @return HasMany<EmancipationEvidence, $this> */
    public function emancipationEvidences(): HasMany
    {
        return $this->hasMany(EmancipationEvidence::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    protected static function booted(): void
    {
        static::saving(function (self $request): void {
            foreach ([
                'student_year_semester', 'legal_guardian_name', 'legal_guardian_cpf',
                'legal_guardian_kinship', 'legal_guardian_email', 'activities',
                'internship_sector', 'observations',
            ] as $attribute) {
                $value = $request->getAttribute($attribute);

                if (is_string($value)) {
                    $request->{$attribute} = trim($value) ?: null;
                }
            }

            $attributes = $request->getAttributes();
            $status = InternshipRequestStatus::tryFrom($attributes['status'] ?? '');
            $declaration = LegalCapacityDeclaration::tryFrom($attributes['legal_capacity_declaration'] ?? '');
            $isIncomplete = in_array($status, [InternshipRequestStatus::Draft, InternshipRequestStatus::Withdrawn], true);
            $required = $isIncomplete ? 'nullable' : 'required';
            $isAccepted = $status === InternshipRequestStatus::Accepted;
            $isMinor = $declaration === LegalCapacityDeclaration::Minor && ! $isIncomplete;
            $selectsCourse = ! $request->exists || $request->isDirty('course_id');
            $selectsType = ! $request->exists || $request->isDirty('internship_type_id');
            $courseExists = Rule::exists(Course::class, 'id');
            $typeExists = Rule::exists(InternshipType::class, 'id');

            if ($selectsCourse) {
                $courseExists->whereNull('deactivated_at');
            }

            if ($selectsType) {
                $typeExists->whereNull('deactivated_at');
            }

            $data = [...$attributes, 'weekly_hours' => $request->weekly_hours];
            $validator = Validator::make($data, [
                'affiliation_id' => ['required', 'integer', Rule::exists(Affiliation::class, 'id')->where('type', AffiliationType::Student->value)->whereNull('deactivated_at')],
                'course_id' => [$required, 'integer', $courseExists],
                'internship_type_id' => [$required, 'integer', $typeExists],
                'advisor_affiliation_id' => [$isAccepted ? 'required' : 'nullable', 'integer', Rule::exists(Affiliation::class, 'id')->where('type', AffiliationType::Advisor->value)],
                'granting_party_id' => ['nullable', 'integer', Rule::exists(GrantingParty::class, 'id')],
                'granting_party_registration_request_id' => ['nullable', 'integer', Rule::exists(GrantingPartyRegistrationRequest::class, 'id')],
                'supervisor_affiliation_id' => ['nullable', 'integer', Rule::exists(Affiliation::class, 'id')->where('type', AffiliationType::Supervisor->value)],
                'supervisor_registration_request_id' => ['nullable', 'integer', Rule::exists(SupervisorRegistrationRequest::class, 'id')],
                'student_year_semester' => [$required, 'string', 'max:255'],
                'legal_capacity_declaration' => [$required, Rule::enum(LegalCapacityDeclaration::class)],
                'legal_guardian_name' => [$isMinor ? 'required' : 'nullable', 'string', 'max:255'],
                'legal_guardian_cpf' => [$isMinor ? 'required' : 'nullable', 'string'],
                'legal_guardian_kinship' => [$isMinor ? 'required' : 'nullable', 'string', 'max:80'],
                'legal_guardian_email' => [$isMinor ? 'required' : 'nullable', 'email', 'max:255'],
                'activities' => [$required, 'string'],
                'internship_sector' => ['nullable', 'string', 'max:255'],
                'weekly_hours' => [$required, 'array'],
                'planned_start_date' => [$required, 'date'],
                'projected_end_date' => [$required, 'date', 'after_or_equal:planned_start_date'],
                'is_remunerated' => [$required, 'boolean'],
                'grant_value' => [Rule::requiredIf(! $isIncomplete && $request->is_remunerated === true), 'nullable', 'numeric', 'gt:0'],
                'transportation_allowance' => ['nullable', 'numeric', 'min:0'],
                'observations' => ['nullable', 'string'],
                'status' => ['required', Rule::enum(InternshipRequestStatus::class)],
                'internship_id' => [$isAccepted ? 'required' : 'nullable', 'integer', Rule::exists(Internship::class, 'id')],
                'terms_accepted_at' => [$required, 'date'],
            ]);

            $validator->after(function (LaravelValidator $validator) use ($request, $status, $declaration, $isIncomplete): void {
                $owner = Affiliation::find($request->affiliation_id);
                if ($owner && $request->course_id !== null && $owner->course_id !== $request->course_id) {
                    $validator->errors()->add('course_id', 'O curso deve pertencer ao vínculo discente.');
                }

                $type = InternshipType::find($request->internship_type_id);
                if ($type && $request->course_id !== null && $type->course_id !== $request->course_id) {
                    $validator->errors()->add('internship_type_id', 'O tipo de estágio deve pertencer ao curso selecionado.');
                }

                if ($type && ($type->max_daily_hours < 6 || $type->max_weekly_hours < 30)) {
                    $validator->errors()->add('internship_type_id', 'Os limites do tipo de estágio são inválidos.');
                }

                foreach ([
                    ['granting_party_id', 'granting_party_registration_request_id'],
                    ['supervisor_affiliation_id', 'supervisor_registration_request_id'],
                ] as [$existing, $pending]) {
                    $hasExisting = $request->{$existing} !== null;
                    $hasPending = $request->{$pending} !== null;
                    if ($hasExisting && $hasPending || (! $isIncomplete && ! $hasExisting && ! $hasPending)) {
                        $validator->errors()->add($existing, 'Informe exatamente uma referência para este cadastro.');
                    }
                    if ($status === InternshipRequestStatus::Accepted && $hasPending) {
                        $validator->errors()->add($pending, 'O cadastro pendente deve estar resolvido antes do aceite.');
                    }
                }

                if ($declaration !== LegalCapacityDeclaration::Minor && ! $isIncomplete && (filled($request->legal_guardian_name) || filled($request->legal_guardian_cpf) || filled($request->legal_guardian_kinship) || filled($request->legal_guardian_email))) {
                    $validator->errors()->add('legal_capacity_declaration', 'Dados do responsável são exclusivos da opção menor de idade.');
                }

                if ($declaration === LegalCapacityDeclaration::Adult && ! $isIncomplete && ! $validator->errors()->has('terms_accepted_at')) {
                    $birthDate = $owner?->user?->personalData?->birth_date;
                    if ($birthDate === null || $birthDate->copy()->addYears(18)->isAfter($request->terms_accepted_at)) {
                        $validator->errors()->add('legal_capacity_declaration', 'A opção maior de idade exige 18 anos completos na data do envio.');
                    }
                }

                if ($declaration === LegalCapacityDeclaration::EmancipatedMinor && ! $isIncomplete) {
                    $evidences = $request->emancipationEvidences()->whereHas('media', fn ($query) => $query->where('collection_name', 'emancipation_evidence'));
                    if (! $evidences->exists()) {
                        $validator->errors()->add('legal_capacity_declaration', 'O envio exige comprovante de emancipação anexado.');
                    }

                    if ($status === InternshipRequestStatus::Accepted && ! $evidences->where('status', EmancipationEvidenceStatus::Approved->value)->exists()) {
                        $validator->errors()->add('legal_capacity_declaration', 'O aceite exige comprovante de emancipação aprovado.');
                    }
                }

                if ($request->is_remunerated === false && (filled($request->grant_value) || filled($request->transportation_allowance))) {
                    $validator->errors()->add('is_remunerated', 'Valores monetários devem ficar vazios quando não há remuneração.');
                }

                $hours = $request->weekly_hours;
                if (! is_array($hours)) {
                    return;
                }

                $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
                if (array_diff(array_keys($hours), $days) !== [] || (! $isIncomplete && array_diff($days, array_keys($hours)) !== [])) {
                    $validator->errors()->add('weekly_hours', 'A jornada deve informar apenas os sete dias da semana.');

                    return;
                }

                $total = 0;
                foreach ($hours as $hoursInDay) {
                    if (! is_int($hoursInDay) || $hoursInDay < 0 || ($type && $hoursInDay > $type->max_daily_hours)) {
                        $validator->errors()->add('weekly_hours', 'A jornada diária deve ser inteira e respeitar o limite do tipo.');

                        return;
                    }

                    $total += $hoursInDay;
                }

                if (! $isIncomplete && ($total === 0 || ($type && $total > $type->max_weekly_hours))) {
                    $validator->errors()->add('weekly_hours', 'A carga semanal deve ser positiva e respeitar o limite do tipo.');
                }
            });

            $validator->validate();
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages([
                'internship_request' => 'Solicitações de estágio não podem ser excluídas fisicamente.',
            ]);
        });
    }
}
