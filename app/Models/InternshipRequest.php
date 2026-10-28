<?php

namespace App\Models;

use App\Casts\CpfCast;
use App\Concerns\InternshipRequestValidationRules;
use App\Enums\AffiliationType;
use App\Enums\EmancipationEvidenceStatus;
use App\Enums\InternshipRequestStatus;
use App\Enums\LegalCapacityDeclaration;
use App\Helpers\DateHelper;
use App\Support\InternshipCalculationHistory;
use App\Support\InternshipPlannedPauses;
use Carbon\CarbonInterface;
use Database\Factories\InternshipRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $affiliation_id
 * @property int|null $course_id
 * @property int|null $internship_type_id
 * @property int|null $advisor_affiliation_id
 * @property string|null $co_advisor_name
 * @property int|null $granting_party_id
 * @property int|null $granting_party_registration_request_id
 * @property int|null $supervisor_affiliation_id
 * @property int|null $supervisor_registration_request_id
 * @property int|null $internship_id
 * @property int|null $student_address_id
 * @property int|null $workplace_address_id
 * @property InternshipRequestStatus $status
 * @property LegalCapacityDeclaration|null $legal_capacity_declaration
 * @property array<string, int>|null $weekly_hours
 * @property array<string|int, array{starts_at: string|null, ends_at: string|null, reason: string|null}>|null $planned_pauses
 * @property array<string, mixed>|null $student_snapshot
 * @property array<string, mixed>|null $internship_type_snapshot
 * @property array<string, mixed>|null $granting_party_snapshot
 * @property array<string, mixed>|null $supervisor_snapshot
 * @property bool|null $is_remunerated
 * @property Carbon|null $planned_start_date
 * @property CarbonInterface|null $projected_end_date
 * @property Carbon|null $terms_accepted_at
 */
#[Fillable([
    'affiliation_id', 'course_id', 'internship_type_id', 'advisor_affiliation_id', 'co_advisor_name',
    'granting_party_id', 'granting_party_registration_request_id',
    'supervisor_affiliation_id', 'supervisor_registration_request_id',
    'student_year_semester', 'legal_capacity_declaration', 'legal_guardian_name',
    'legal_guardian_cpf', 'legal_guardian_kinship', 'legal_guardian_email',
    'activities', 'internship_sector', 'weekly_hours', 'planned_pauses', 'planned_start_date',
    'projected_end_date', 'is_remunerated', 'grant_value',
    'transportation_allowance', 'observations', 'status', 'internship_id',
    'terms_accepted_at',
])]
#[Hidden(['legal_guardian_cpf', 'student_snapshot', 'supervisor_snapshot'])]
class InternshipRequest extends Model
{
    /** @use HasFactory<InternshipRequestFactory> */
    use HasFactory;

    use InternshipRequestValidationRules;
    use LogsActivity;
    use Searchable;

    /** @return array{id: int, student_name: string, registration_number: string, campus_id: int, course_id: int, status: string, updated_at: int} */
    public function toSearchableArray(): array
    {
        return [
            'id' => (int) $this->id,
            'student_name' => (string) ($this->student_snapshot['name'] ?? ''),
            'registration_number' => (string) ($this->student_snapshot['registration_number'] ?? ''),
            'campus_id' => (int) $this->affiliation->campus_id,
            'course_id' => (int) $this->course_id,
            'status' => $this->status->value,
            'updated_at' => (int) $this->updated_at?->getTimestamp(),
        ];
    }

    /**
     * @param  Collection<int, self>  $models
     * @return Collection<int, self>
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        return $models->load('affiliation');
    }

    /** @var array<string, mixed>|null */
    public ?array $calculationSnapshot = null;

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
            'planned_pauses' => 'array',
            'planned_start_date' => 'date',
            'projected_end_date' => 'date',
            'is_remunerated' => 'boolean',
            'grant_value' => 'decimal:2',
            'transportation_allowance' => 'decimal:2',
            'status' => InternshipRequestStatus::class,
            'internship_id' => 'integer',
            'terms_accepted_at' => 'datetime',
            'student_snapshot' => 'array',
            'internship_type_snapshot' => 'array',
            'granting_party_snapshot' => 'array',
            'supervisor_snapshot' => 'array',
            'student_address_id' => 'integer',
            'workplace_address_id' => 'integer',
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

    /** @return HasMany<InternshipRequestCorrection, $this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(InternshipRequestCorrection::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnly(['decision_reason'])->logExcept(['legal_guardian_cpf'])
            ->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Address, $this> */
    public function studentAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'student_address_id');
    }

    /** @return BelongsTo<Address, $this> */
    public function workplaceAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'workplace_address_id');
    }

    protected static function booted(): void
    {
        static::saved(function (self $request): void {
            app(InternshipCalculationHistory::class)->record($request);
        });
        static::saving(function (self $request): void {
            if ($request->exists && ! in_array($request->getRawOriginal('status'), ['draft', 'pending_correction'], true)
                && $request->isDirty(['student_snapshot', 'internship_type_snapshot', 'granting_party_snapshot', 'supervisor_snapshot', 'student_address_id', 'workplace_address_id'])) {
                throw ValidationException::withMessages(['snapshot' => 'Os dados históricos enviados estão protegidos.']);
            }
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
            $partyExists = Rule::exists(GrantingParty::class, 'id');
            if (! $request->exists || $request->isDirty('granting_party_id')) {
                $partyExists->whereNull('deactivated_at');
            }
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

            $advisorExists = Rule::exists(Affiliation::class, 'id')->where('type', AffiliationType::Advisor->value);
            if (! $request->exists || $request->isDirty('advisor_affiliation_id') || in_array($status, [InternshipRequestStatus::Submitted, InternshipRequestStatus::Accepted], true)) {
                $advisorExists->whereNull('deactivated_at');
            }

            app(InternshipPlannedPauses::class)->validate($request->planned_pauses, $request->planned_start_date?->toDateString(), complete: ! $isIncomplete);
            $data = [...$attributes, 'weekly_hours' => $request->weekly_hours];
            $validator = Validator::make($data, [
                'affiliation_id' => ['required', 'integer', Rule::exists(Affiliation::class, 'id')->where('type', AffiliationType::Student->value)->whereNull('deactivated_at')],
                'course_id' => [$required, 'integer', $courseExists],
                'internship_type_id' => [$required, 'integer', $typeExists],
                'advisor_affiliation_id' => [$required, 'integer', $advisorExists],
                'co_advisor_name' => ['nullable', 'string', 'max:255'],
                'granting_party_id' => ['nullable', 'integer', $partyExists],
                'granting_party_registration_request_id' => ['nullable', 'integer', Rule::exists(GrantingPartyRegistrationRequest::class, 'id')],
                'supervisor_affiliation_id' => ['nullable', 'integer', Rule::exists(Affiliation::class, 'id')->where('type', AffiliationType::Supervisor->value)],
                'supervisor_registration_request_id' => ['nullable', 'integer', Rule::exists(SupervisorRegistrationRequest::class, 'id')],
                'student_year_semester' => [$required, 'string', 'max:255', ...($isIncomplete ? [] : [Rule::in($request->internship_type_snapshot['rules']['allowed_academic_periods'] ?? $request->internshipType->allowed_academic_periods ?? [])])],
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
            ], messages: ['student_year_semester.in' => 'Selecione um ano ou semestre permitido para este tipo de estágio.'], attributes: $request->internshipFieldLabels());

            $validator->after(function (LaravelValidator $validator) use ($request, $status, $declaration, $isIncomplete): void {
                $owner = Affiliation::find($request->affiliation_id);
                if ($request->advisor_affiliation_id !== null && $owner !== null
                    && Affiliation::query()->whereKey($request->advisor_affiliation_id)->value('campus_id') !== $owner->campus_id) {
                    $validator->errors()->add('advisor_affiliation_id', 'O orientador deve pertencer ao campus do vínculo discente.');
                }
                if ($owner && $request->course_id !== null && $owner->course_id !== $request->course_id) {
                    $validator->errors()->add('course_id', 'O curso deve pertencer ao vínculo discente.');
                }

                $grantingParty = $request->granting_party_id === null
                    ? null
                    : GrantingParty::find($request->granting_party_id);

                if ($grantingParty && $owner && $grantingParty->campus_id !== $owner->campus_id) {
                    $validator->errors()->add('granting_party_id', 'A parte concedente deve pertencer ao campus do vínculo discente.');
                }

                $grantingPartyRequest = $request->granting_party_registration_request_id === null
                    ? null
                    : GrantingPartyRegistrationRequest::find($request->granting_party_registration_request_id);

                if ($grantingPartyRequest && $owner && $grantingPartyRequest->campus_id !== $owner->campus_id) {
                    $validator->errors()->add('granting_party_registration_request_id', 'A solicitação de concedente deve pertencer ao campus do vínculo discente.');
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

                if (! $isIncomplete && ! $validator->errors()->has('terms_accepted_at')) {
                    $snapshotBirth = $request->student_snapshot['birth_date'] ?? null;
                    $birth = $snapshotBirth ?? $owner?->user?->personalData?->birth_date?->toDateString();
                    $adult = DateHelper::isAdult($birth, $request->terms_accepted_at);
                    if ($adult === null || $adult !== ($declaration === LegalCapacityDeclaration::Adult)) {
                        $validator->errors()->add('legal_capacity_declaration', 'A capacidade civil deve corresponder à data de nascimento na data do envio.');
                    }
                }

                if ($declaration === LegalCapacityDeclaration::EmancipatedMinor && ! $isIncomplete) {
                    $latestEvidence = $request->emancipationEvidences()->with('media')->latest('id')->first();
                    if ($latestEvidence === null || ! $latestEvidence->hasMedia('emancipation_evidence')
                        || ($status !== InternshipRequestStatus::PendingCorrection && $latestEvidence->status === EmancipationEvidenceStatus::Returned)) {
                        $validator->errors()->add('legal_capacity_declaration', 'O envio exige comprovante de emancipação anexado.');
                    }

                    if ($status === InternshipRequestStatus::Accepted && $latestEvidence?->status !== EmancipationEvidenceStatus::Approved) {
                        $validator->errors()->add('legal_capacity_declaration', 'O aceite exige comprovante de emancipação aprovado.');
                    }
                }

                if ($request->is_remunerated === false && (filled($request->grant_value) || filled($request->transportation_allowance))) {
                    $validator->errors()->add('is_remunerated', 'Valores monetários devem ficar vazios quando não há remuneração.');
                }

                $hours = $request->getAttribute('weekly_hours');
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
