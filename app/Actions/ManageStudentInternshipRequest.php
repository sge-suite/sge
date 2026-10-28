<?php

namespace App\Actions;

use App\Concerns\InternshipRequestValidationRules;
use App\Enums\AffiliationType;
use App\Enums\InternshipRequestCorrectionStatus;
use App\Enums\InternshipRequestStatus;
use App\Enums\LegalCapacityDeclaration;
use App\Enums\PartyDocumentType;
use App\Enums\RegistrationRequestStatus;
use App\Helpers\DateHelper;
use App\Models\Address;
use App\Models\Affiliation;
use App\Models\City;
use App\Models\GrantingParty;
use App\Models\GrantingPartyRegistrationRequest;
use App\Models\InternshipRequest;
use App\Models\InternshipType;
use App\Models\SupervisorRegistrationRequest;
use App\Models\User;
use App\Support\InternshipEndDateCalculator;
use App\Support\InternshipRegistrationLookup;
use App\Support\InternshipRequestPersonalData;
use App\Support\InternshipRequestSnapshots;
use App\Support\StudentInternshipContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

class ManageStudentInternshipRequest
{
    use InternshipRequestValidationRules;

    public function __construct(private StudentInternshipContext $context, private CauserResolver $causerResolver,
        private InternshipRequestSnapshots $snapshots, private ProjectInternshipRequestEndDate $project,
        private RequestEmailDelivery $emailDelivery, private InternshipRegistrationLookup $registrations) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, int $expectedAffiliationId, ?InternshipRequest $target, string $operation, array $data = [], bool $termsAccepted = false, ?string $expectedUpdatedAt = null): InternshipRequest
    {
        abort_unless(in_array($operation, ['create', 'save', 'submit', 'withdraw'], true), 404);

        return DB::transaction(function () use ($actor, $expectedAffiliationId, $target, $operation, $data, $termsAccepted, $expectedUpdatedAt): InternshipRequest {
            $owner = $this->context->lock($actor, $expectedAffiliationId);
            $request = $target === null ? null : InternshipRequest::query()->lockForUpdate()->findOrFail($target->id);
            if ($operation === 'create') {
                Gate::forUser($actor)->authorize('create', InternshipRequest::class);
                $types = InternshipType::query()->active()->where('course_id', $owner->course_id)->limit(2)->get();

                return $owner->internshipRequests()->where('status', InternshipRequestStatus::Draft)->first()
                    ?? $this->causerResolver->withCauser($owner, fn (): InternshipRequest => InternshipRequest::create(['affiliation_id' => $owner->id,
                        'course_id' => $owner->course_id, 'internship_type_id' => $types->count() === 1 ? $types->first()->id : null]));
            }
            abort_if($request === null, 404);
            Gate::forUser($actor)->authorize($operation === 'save' ? 'update' : $operation, $request);
            if ($expectedUpdatedAt !== null && $request->getRawOriginal('updated_at') !== $expectedUpdatedAt) {
                throw ValidationException::withMessages(['request' => 'Esta solicitação mudou em outra janela. Reabra para revisar os dados atuais antes de salvar.']);
            }

            return $this->causerResolver->withCauser($owner, function () use ($request, $owner, $operation, $data, $termsAccepted): InternshipRequest {
                $correction = $request->status === InternshipRequestStatus::PendingCorrection
                    ? $request->corrections()->where('status', InternshipRequestCorrectionStatus::Open)->lockForUpdate()->sole() : null;
                if ($operation === 'withdraw') {
                    Validator::make($data, ['current_password' => ['required', 'string']], attributes: ['current_password' => 'senha atual'])->validate();
                    if (! Hash::check($data['current_password'], $owner->user()->firstOrFail()->password)) {
                        throw ValidationException::withMessages(['current_password' => 'A senha informada está incorreta.']);
                    }
                    $request->status = InternshipRequestStatus::Withdrawn;
                    $request->save();
                    if ($correction !== null) {
                        $correction->update(['status' => InternshipRequestCorrectionStatus::Cancelled, 'resolved_at' => now()]);
                    }

                    return $request;
                }
                $data = $this->normalizeInternshipInput($data);
                $allowed = array_merge(...array_values(self::internshipSections()));
                foreach (array_diff(array_keys($data), $allowed) as $field) {
                    throw ValidationException::withMessages([$field => 'Este campo não pode ser alterado na solicitação.']);
                }
                if ($correction !== null) {
                    $allowed = [];
                    foreach ($correction->affected_sections as $section) {
                        $allowed = [...$allowed, ...(self::internshipSections()[$section] ?? [])];
                    }
                    foreach (array_diff(array_keys($data), $allowed) as $field) {
                        throw ValidationException::withMessages([$field => 'Esta seção não foi liberada para correção.']);
                    }
                }
                $input = Validator::make($data, $this->internshipInputRules(), attributes: $this->internshipFieldLabels())->validate();
                $request->course_id = $owner->course_id;
                if (array_key_exists('personal_data', $input)) {
                    app(InternshipRequestPersonalData::class)->saveDraft($input['personal_data'], $owner, $owner->user()->firstOrFail());
                    $request->student_snapshot = array_replace($request->student_snapshot ?? [], ['personal_data_draft' => array_replace_recursive(
                        app(InternshipRequestPersonalData::class)->values($request, $owner), $input['personal_data'],
                    )]);
                }
                if (array_key_exists('granting_party_change_note', $input)) {
                    $request->granting_party_snapshot = array_replace($request->granting_party_snapshot ?? [], ['reported_changes' => $input['granting_party_change_note']]);
                }
                $scalarFields = array_diff($allowed, ['granting_party_change_note', 'personal_data', 'granting_party_mode', 'granting_party_proposal', 'supervisor_mode', 'supervisor_proposal', 'supervisor_professional', 'weekly_hours']);
                $request->fill(Arr::only($input, $scalarFields));
                if (array_key_exists('weekly_hours', $input)) {
                    $hours = $input['weekly_hours'] === null ? null : array_replace($request->weekly_hours ?? [], $input['weekly_hours']);
                    $request->weekly_hours = $hours === null ? null : array_map(fn (mixed $value): int => (int) $value, array_filter($hours, fn (mixed $value): bool => $value !== null));
                }
                $this->applyChoices($request, $owner, $input);
                if (in_array('legal_capacity_declaration', $allowed, true) || in_array('personal_data', $allowed, true)) {
                    $birth = app(InternshipRequestPersonalData::class)->values($request, $owner)['birth_date'] ?? null;
                    $adult = DateHelper::isAdult($birth);
                    if ($adult === true) {
                        $request->legal_capacity_declaration = LegalCapacityDeclaration::Adult;
                    } elseif ($request->legal_capacity_declaration === LegalCapacityDeclaration::Adult) {
                        $request->legal_capacity_declaration = null;
                    }
                }
                if ($request->is_remunerated === false) {
                    $request->grant_value = null;
                    $request->transportation_allowance = null;
                }
                if ($request->legal_capacity_declaration !== LegalCapacityDeclaration::Minor) {
                    foreach (['legal_guardian_name', 'legal_guardian_cpf', 'legal_guardian_kinship', 'legal_guardian_email'] as $field) {
                        $request->{$field} = null;
                    }
                }
                $this->assertRelations($request, $owner);
                $this->project->validateStartDate($request);
                $request->projected_end_date = null;
                if ($operation === 'submit') {
                    Validator::make(['terms_accepted' => $termsAccepted], ['terms_accepted' => ['accepted']],
                        ['terms_accepted.accepted' => 'Confirme que leu e concorda com as normas e com os dados informados.'])->validate();
                    $this->submit($request, $owner, $correction?->affected_sections);
                    if ($correction !== null) {
                        $correction->update(['status' => InternshipRequestCorrectionStatus::Responded, 'responded_at' => now()]);
                    }
                    $this->emailDelivery->internshipRequestSubmitted($request, (string) Str::uuid(), $correction !== null);
                } else {
                    if ($request->status === InternshipRequestStatus::PendingCorrection) {
                        $request->projected_end_date = $this->project->handle($request);
                    }
                    $request->save();
                }

                return $request;
            });
        }, attempts: 3);
    }

    /** @param array<string, mixed> $input */
    private function applyChoices(InternshipRequest $request, Affiliation $owner, array $input): void
    {
        foreach (['granting_party', 'supervisor'] as $kind) {
            $existingField = $kind === 'granting_party' ? 'granting_party_id' : 'supervisor_affiliation_id';
            $pendingField = $kind.'_registration_request_id';
            $mode = $input[$kind.'_mode'] ?? null;
            if (($mode === 'pending' && filled($input[$existingField] ?? null))
                || ($mode === 'existing' && ! empty($input[$kind.'_proposal']))) {
                throw ValidationException::withMessages([$kind.'_mode' => 'Escolha um cadastro existente ou um pedido de cadastro, sem combinar os dois.']);
            }
            if ($mode === 'pending' || array_key_exists($kind.'_proposal', $input)) {
                $document = $input[$kind.'_proposal'][$kind === 'granting_party' ? 'document_number' : 'cpf'] ?? null;
                $this->assertRegistrationNotAvailable($owner, $kind, $document);
            }
            if ($mode === 'existing') {
                $request->{$pendingField} = null;
            } elseif ($mode === 'pending') {
                $request->{$existingField} = null;
            }
            if ($mode === 'pending' || array_key_exists($kind.'_proposal', $input)) {
                if ($mode !== 'pending' && $request->{$existingField} !== null) {
                    throw ValidationException::withMessages([$kind.'_mode' => 'Escolha apenas um caminho para o cadastro.']);
                }
                $class = $kind === 'granting_party' ? GrantingPartyRegistrationRequest::class : SupervisorRegistrationRequest::class;
                $pending = $request->{$pendingField} === null ? null : $class::query()->lockForUpdate()->findOrFail($request->{$pendingField});
                if ($pending === null || $pending->status !== RegistrationRequestStatus::Draft) {
                    $pending = $pending?->replicate(['status', 'reviewed_at', 'decision_reason', $existingField]) ?? new $class;
                    $pending->status = RegistrationRequestStatus::Draft;
                    if ($pending instanceof GrantingPartyRegistrationRequest) {
                        $pending->campus_id = $owner->campus_id;
                    }
                }
                $proposal = $input[$kind.'_proposal'] ?? [];
                if ($pending instanceof GrantingPartyRegistrationRequest && array_key_exists('city_id', $proposal)) {
                    $state = $proposal['uf'] ?? $pending->uf;
                    if ($proposal['city_id'] !== null && $state === null) {
                        throw ValidationException::withMessages(['granting_party_proposal.uf' => 'Selecione a UF antes de escolher a cidade.']);
                    }
                    $city = $proposal['city_id'] === null ? null : City::query()->forState($state)->whereKey($proposal['city_id'])->first();
                    if ($proposal['city_id'] !== null && $city === null) {
                        throw ValidationException::withMessages(['granting_party_proposal.city_id' => 'Selecione uma cidade da UF informada.']);
                    }
                    $proposal['city'] = $city?->name;
                }
                $pending->fill($proposal);
                if ($pending instanceof GrantingPartyRegistrationRequest) {
                    $hidden = ['field_of_activity', 'professional_council', 'council_registration_number', 'credentialing_process_number'];
                    $hidden = [...$hidden, ...($pending->document_type === PartyDocumentType::CNPJ
                        ? ['street', 'number', 'neighborhood', 'zip_code'] : ['representative_name', 'representative_role'])];
                    foreach ($hidden as $field) {
                        $pending->{$field} = null;
                    }
                }
                if ($pending instanceof SupervisorRegistrationRequest) {
                    $this->clearQualificationBranch($pending);
                }
                $this->saveProposal($pending, $kind.'_proposal');
                $request->{$pendingField} = $pending->id;
            }
        }
        if (array_key_exists('supervisor_professional', $input)) {
            $professional = array_replace($request->supervisor_snapshot ?? [], $input['supervisor_professional']);
            if (isset($professional['has_academic_background'])) {
                $professional['has_academic_background'] = (bool) $professional['has_academic_background'];
                foreach ($professional['has_academic_background'] ? ['experience_time'] : ['training_course', 'education_level'] as $field) {
                    $professional[$field] = null;
                }
            }
            $request->supervisor_snapshot = $professional;
        }
    }

    private function clearQualificationBranch(SupervisorRegistrationRequest $proposal): void
    {
        if ($proposal->has_academic_background !== null) {
            foreach ($proposal->has_academic_background ? ['experience_time'] : ['training_course', 'education_level'] as $field) {
                $proposal->{$field} = null;
            }
        }
    }

    private function assertRegistrationNotAvailable(Affiliation $owner, string $kind, ?string $document): void
    {
        if ($document === null || $document === '') {
            return;
        }
        $exists = $kind === 'granting_party'
            ? $this->registrations->grantingPartyMatches($owner, $document)->isNotEmpty()
            : $this->registrations->supervisorMatch($owner, $document) !== null;
        if ($exists) {
            $field = $kind === 'granting_party' ? 'granting_party_proposal.document_number' : 'supervisor_proposal.cpf';
            throw ValidationException::withMessages([$field => 'Já existe um cadastro disponível para este documento. Volte à busca e confira o cadastro antes de continuar.']);
        }
    }

    private function saveProposal(GrantingPartyRegistrationRequest|SupervisorRegistrationRequest $proposal, string $prefix): void
    {
        try {
            $proposal->save();
        } catch (ValidationException $exception) {
            $messages = [];
            foreach ($exception->errors() as $field => $errors) {
                $messages[$prefix.'.'.$field] = $errors;
            }
            throw ValidationException::withMessages($messages);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([$prefix.'.document_number' => 'Confira o tipo e o número do documento informado.']);
        }
    }

    public function assertRelations(InternshipRequest $request, Affiliation $owner): void
    {
        if ($request->advisor_affiliation_id !== null && ! Affiliation::query()->active()
            ->where('campus_id', $owner->campus_id)->where('type', AffiliationType::Advisor->value)
            ->whereKey($request->advisor_affiliation_id)->lockForUpdate()->first()) {
            throw ValidationException::withMessages(['advisor_affiliation_id' => 'Selecione um orientador ativo do seu campus.']);
        }
        if ($request->internship_type_id !== null) {
            $type = InternshipType::query()->active()->where('course_id', $owner->course_id)->lockForUpdate()->find($request->internship_type_id);
            if ($type === null) {
                throw ValidationException::withMessages(['internship_type_id' => 'Escolha um tipo ativo do seu curso.']);
            }
        }
        if ($request->granting_party_id !== null && ! GrantingParty::query()->active()->where('campus_id', $owner->campus_id)->lockForUpdate()->find($request->granting_party_id)) {
            throw ValidationException::withMessages(['granting_party_id' => 'Escolha uma concedente ativa do seu campus.']);
        }
        if ($request->supervisor_affiliation_id !== null && ! Affiliation::query()->active()->where('type', AffiliationType::Supervisor->value)
            ->where('campus_id', $owner->campus_id)->lockForUpdate()->find($request->supervisor_affiliation_id)) {
            throw ValidationException::withMessages(['supervisor_affiliation_id' => 'Escolha um supervisor ativo do seu campus.']);
        }
        foreach (['granting_party', 'supervisor'] as $kind) {
            $field = $kind.'_registration_request_id';
            if ($request->{$field} !== null) {
                $relation = $kind === 'granting_party' ? 'grantingPartyRegistrationRequest' : 'supervisorRegistrationRequest';
                $pending = $request->{$relation}()->lockForUpdate()->firstOrFail();
                if (in_array($pending->status, [RegistrationRequestStatus::Rejected, RegistrationRequestStatus::Cancelled], true)) {
                    throw ValidationException::withMessages([$kind.'_mode' => 'O pedido anterior foi encerrado. Selecione um cadastro ou faça um novo pedido.']);
                }
            }
        }
    }

    /** @param list<string>|null $sections */
    private function submit(InternshipRequest $request, Affiliation $owner, ?array $sections): void
    {
        $full = $sections === null;
        $type = $request->internshipType()->first();
        $periods = $full || in_array('internship', $sections, true)
            ? ($type->allowed_academic_periods ?? [])
            : ($request->internship_type_snapshot['rules']['allowed_academic_periods'] ?? $type->allowed_academic_periods ?? []);
        Validator::make(['advisor_affiliation_id' => $request->advisor_affiliation_id, 'internship_type_id' => $type?->id, 'student_year_semester' => $request->student_year_semester, 'activities' => $request->activities, 'internship_sector' => $request->internship_sector,
            'planned_start_date' => $request->planned_start_date?->toDateString(), 'weekly_hours' => $request->weekly_hours], [
                'student_year_semester' => ['required', Rule::in($periods)],
                'advisor_affiliation_id' => ['required'], 'internship_type_id' => ['required'], 'activities' => ['required'], 'internship_sector' => ['required'],
                'planned_start_date' => ['required', 'date', 'after_or_equal:'.now(config('app.timezone'))->addDays(7)->toDateString()],
                'weekly_hours' => ['required', 'array', 'required_array_keys:'.implode(',', InternshipEndDateCalculator::DAYS)],
            ], ['planned_start_date.after_or_equal' => 'A data de início deve respeitar sete dias corridos após o envio.',
                'student_year_semester.in' => 'Selecione um ano ou semestre permitido para este tipo de estágio.'], $this->internshipFieldLabels())->validate();
        foreach (['grantingPartyRegistrationRequest' => 'granting_party_proposal', 'supervisorRegistrationRequest' => 'supervisor_proposal'] as $relation => $prefix) {
            $pending = $request->{$relation}()->first();
            $kind = $relation === 'grantingPartyRegistrationRequest' ? 'granting_party' : 'supervisor';
            if ($pending !== null && ($full || in_array($kind, $sections, true))) {
                $this->assertRegistrationNotAvailable($owner, $kind,
                    $pending instanceof GrantingPartyRegistrationRequest ? $pending->document_number : $pending->cpf);
            }
            if ($pending?->status === RegistrationRequestStatus::Draft) {
                $pending->status = RegistrationRequestStatus::Submitted;
                $this->saveProposal($pending, $prefix);
            }
        }
        if ($full || in_array('personal_data', $sections, true)) {
            app(InternshipRequestPersonalData::class)->apply($request, $owner, $owner->user()->firstOrFail());
            $this->snapshots->student($request, $owner);
        } else {
            $request->student_snapshot = array_replace($request->student_snapshot ?? [], $request->only(['student_year_semester', 'legal_capacity_declaration', 'legal_guardian_name', 'legal_guardian_cpf', 'legal_guardian_kinship', 'legal_guardian_email']));
        }
        if ($full || in_array('internship', $sections, true)) {
            $request->internship_type_snapshot = $this->snapshots->type($type);
        }
        if ($full || in_array('granting_party', $sections, true)) {
            $party = $request->grantingParty()->with('address')->first();
            $pending = $request->grantingPartyRegistrationRequest()->first();
            $reportedChanges = $request->granting_party_snapshot['reported_changes'] ?? null;
            $request->granting_party_snapshot = $party === null ? ($pending?->only(['document_type', 'document_number', 'name', 'representative_name', 'representative_role', 'phone', 'email', 'city_id', 'city', 'uf', 'street', 'number', 'neighborhood', 'zip_code', 'field_of_activity', 'professional_council', 'council_registration_number', 'credentialing_process_number']) ?? []) + ['source_registration_request_id' => $pending?->id] : $this->snapshots->party($party);
            $request->granting_party_snapshot = array_replace($request->granting_party_snapshot, ['reported_changes' => $reportedChanges]);
            $request->workplace_address_id = $party === null
                ? ($pending->document_type === PartyDocumentType::CPF ? Address::create($pending->only(['city_id', 'street', 'number', 'neighborhood', 'zip_code']))->id : null)
                : app(CopyAddress::class)->handle($party->address)->id;
        }
        if ($full || in_array('supervisor', $sections, true)) {
            $supervisor = $request->supervisorAffiliation()->first();
            $pending = $request->supervisorRegistrationRequest()->first();
            if ($supervisor !== null) {
                $professional = Arr::only($request->supervisor_snapshot ?? [], ['job_role']);
                Validator::make($professional, ['job_role' => ['required', 'string', 'max:255']], attributes: $this->internshipFieldLabels())->validate();
                $request->supervisor_snapshot = $this->snapshots->supervisor($supervisor, $professional);
            } else {
                $request->supervisor_snapshot = ($pending?->only(['name', 'phone', 'email', 'job_role', 'has_academic_background', 'training_course', 'education_level', 'experience_time']) ?? []) + ['source_registration_request_id' => $pending?->id];
            }
        }
        Validator::make(['granting_party_phone' => $request->granting_party_snapshot['phone'] ?? null, 'granting_party_email' => $request->granting_party_snapshot['email'] ?? null],
            ['granting_party_phone' => ['required'], 'granting_party_email' => ['required', 'email']], attributes: ['granting_party_phone' => 'telefone da concedente', 'granting_party_email' => 'e-mail da concedente'])->validate();
        Validator::make(['supervisor_phone' => $request->supervisor_snapshot['phone'] ?? null, 'supervisor_email' => $request->supervisor_snapshot['email'] ?? null],
            ['supervisor_phone' => ['required'], 'supervisor_email' => ['required', 'email']], attributes: ['supervisor_phone' => 'telefone do supervisor', 'supervisor_email' => 'e-mail do supervisor'])->validate();
        $birth = $request->student_snapshot['birth_date'] ?? null;
        $adult = DateHelper::isAdult($birth);
        if ($adult === null || $adult !== ($request->legal_capacity_declaration === LegalCapacityDeclaration::Adult)) {
            throw ValidationException::withMessages(['legal_capacity_declaration' => 'Confira a capacidade civil e a data de nascimento nos dados pessoais.']);
        }
        $request->terms_accepted_at = now();
        $request->projected_end_date = $this->project->handle($request);
        $request->status = InternshipRequestStatus::Submitted;
        $request->save();
    }
}
