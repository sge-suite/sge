<?php

use App\Actions\ManageStudentInternshipRequest;
use App\Actions\StoreEmancipationEvidence;
use App\Concerns\InternshipRequestValidationRules;
use App\Concerns\InteractsWithAddressLookup;
use App\Concerns\SupervisorProfessionalDataValidationRules;
use App\Enums\AffiliationType;
use App\Enums\EducationLevel;
use App\Enums\EmancipationEvidenceStatus;
use App\Enums\InternshipRequestCorrectionStatus;
use App\Enums\InternshipRequestStatus;
use App\Enums\LegalCapacityDeclaration;
use App\Helpers\BrazilianDocumentHelper;
use App\Helpers\DigitsHelper;
use App\Models\Affiliation;
use App\Models\City;
use App\Models\EmancipationEvidence;
use App\Models\InternshipRequest;
use App\Models\InternshipType;
use App\Support\InternshipEndDateCalculator;
use App\Support\InternshipPlannedPauses;
use App\Support\InternshipRegistrationLookup;
use App\Support\InternshipRequestPersonalData;
use App\Support\StudentInternshipContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Solicitação de estágio')] class extends Component
{
    use InternshipRequestValidationRules;
    use InteractsWithAddressLookup;
    use SupervisorProfessionalDataValidationRules;
    use WithFileUploads;

    #[Locked] public int $requestId;
    #[Locked] public int $affiliationId;
    #[Locked] public int $userId;
    #[Locked] public string $savedUpdatedAt;
    public int $step = 0;
    public int $navigationStep = 0;
    public bool $termsAccepted = false;
    public bool $showWithdrawal = false;
    public string $withdrawalPassword = '';
    public string|int|null $partyCityId = '';
    public string $partySearch = '';
    public string $supervisorSearch = '';
    public string $partyMatchMessage = '';
    public string $supervisorMatchMessage = '';
    private ?int $matchedRegistrationStep = null;
    public $evidence;

    public const STEPS = [
        ['title' => 'Seus dados', 'section' => 'personal_data'],
        ['title' => 'Seu curso e estágio', 'section' => 'internship'],
        ['title' => 'Concedente', 'section' => 'granting_party'],
        ['title' => 'Supervisor e cargo', 'section' => 'supervisor'],
        ['title' => 'Atividades', 'section' => 'activities'],
        ['title' => 'Capacidade civil', 'section' => 'legal_capacity'],
        ['title' => 'Jornada, início e pausas', 'section' => 'schedule'],
        ['title' => 'Remuneração', 'section' => 'remuneration'],
        ['title' => 'Revisar e enviar', 'section' => 'review'],
    ];

    public const SECTION_LABELS = ['personal_data' => 'Dados pessoais', 'internship' => 'Tipo, ano/semestre e orientador',
        'granting_party' => 'Concedente e endereço', 'supervisor' => 'Supervisor e cargo', 'activities' => 'Atividades e setor',
        'schedule' => 'Jornada e pausas', 'planned_start_date' => 'Data de início', 'legal_capacity' => 'Capacidade civil', 'remuneration' => 'Remuneração'];

    public function mount(InternshipRequest $internshipRequest, StudentInternshipContext $context): void
    {
        Gate::authorize('view', $internshipRequest);
        $this->requestId = $internshipRequest->id;
        $this->affiliationId = $context->student(Auth::user())->id;
        $this->userId = Auth::id();
        $this->loadValues();
        if ($internshipRequest->status === InternshipRequestStatus::Draft && empty($this->values['internship_type_id']) && $this->choices['types']->count() === 1) {
            $this->values['internship_type_id'] = $this->choices['types']->first()->id;
        }
        if (! Auth::user()->can('update', $internshipRequest)) {
            $this->step = 8;
        } elseif ($internshipRequest->status === InternshipRequestStatus::PendingCorrection) {
            foreach (self::STEPS as $index => $item) {
                if (in_array($item['section'], $this->editableSections, true) || ($index === 6 && in_array('planned_start_date', $this->editableSections, true))) {
                    $this->step = $index;
                    break;
                }
            }
        }
        $this->navigationStep = $this->step;
    }

    public function hydrate(StudentInternshipContext $context): void
    {
        abort_unless(Auth::id() === $this->userId && $context->student(Auth::user())?->id === $this->affiliationId, 403, 'O vínculo selecionado mudou. Reabra a solicitação.');
        Gate::authorize('view', $this->record);
        abort_unless($this->step >= 0 && $this->step < count(self::STEPS), 404);
    }

    #[Computed]
    public function record(): InternshipRequest
    {
        $request = InternshipRequest::query()->with(['internship', 'advisorAffiliation.user', 'course', 'internshipType', 'grantingParty.address.city', 'grantingPartyRegistrationRequest',
            'supervisorAffiliation.user.personalData', 'supervisorRegistrationRequest', 'studentAddress.city', 'workplaceAddress.city',
            'emancipationEvidences' => fn ($query) => $query->latest('id'), 'corrections' => fn ($query) => $query->latest('id')])->findOrFail($this->requestId);
        Gate::authorize('view', $request);
        return $request;
    }

    #[Computed]
    public function personal(): array
    {
        $user = Auth::user();
        $profile = $user->personalData()->with('address.city')->first();
        return ['user' => $user, 'profile' => $profile, 'owner' => app(StudentInternshipContext::class)->student($user)];
    }

    #[Computed]
    public function editableSections(): array
    {
        if (! Auth::user()->can('update', $this->record)) {
            return [];
        }
        if ($this->record->status === InternshipRequestStatus::Draft) {
            return array_keys(self::internshipSections());
        }
        return $this->record->corrections->firstWhere('status', InternshipRequestCorrectionStatus::Open)?->affected_sections ?? [];
    }

    #[Computed]
    public function choices(): array
    {
        $owner = $this->personal['owner'];
        $lookup = app(InternshipRegistrationLookup::class);
        $partyQuery = $lookup->grantingParties($owner);
        $supervisorQuery = $lookup->supervisors($owner);
        $partyKey = $this->lookupKey($this->values['granting_party_id'] ?? null);
        $supervisorKey = $this->lookupKey($this->values['supervisor_affiliation_id'] ?? null);
        $selectedParty = $partyKey === null ? null : (clone $partyQuery)->with('address.city')->find($partyKey);
        $selectedSupervisor = $supervisorKey === null ? null : (clone $supervisorQuery)->with('user.personalData')->find($supervisorKey);
        $partyCityKey = $this->lookupKey($this->partyCityId);
        $partyTerm = mb_substr(trim($this->partySearch), 0, 100);
        $partyDocument = BrazilianDocumentHelper::normalizeCnpj($partyTerm);
        $supervisorTerm = mb_substr(trim($this->supervisorSearch), 0, 100);
        $supervisorDigits = DigitsHelper::only($supervisorTerm);
        return [
            'proposalCity' => ($proposalCityKey = $this->lookupKey($this->values['granting_party_proposal']['city_id'] ?? null)) === null ? null : City::find($proposalCityKey),
            'advisors' => Affiliation::query()->active()->where('campus_id', $owner->campus_id)->where('type', AffiliationType::Advisor->value)->with('user')->get()->sortBy(fn (Affiliation $advisor): string => $advisor->user->name),
            'types' => InternshipType::query()->active()->where('course_id', $owner->course_id)->orderBy('name')->get(),
            'parties' => mb_strlen($partyTerm) < 2 && $partyCityKey === null ? collect() : $partyQuery
                ->when($partyCityKey !== null, fn ($query) => $query->whereHas('address', fn ($address) => $address->where('city_id', $partyCityKey)))
                ->when(mb_strlen($partyTerm) >= 2, fn ($query) => $query->where(function ($query) use ($partyTerm, $partyDocument): void {
                    $query->where('name', 'ilike', '%'.$partyTerm.'%');
                    if ($partyDocument !== '') {
                        $query->orWhere('document_number', 'like', '%'.$partyDocument.'%');
                    }
                }))->with('address.city')->orderBy('name')->orderBy('id')->limit(20)->get(),
            'supervisors' => mb_strlen($supervisorTerm) < 2 ? collect() : $supervisorQuery->with('user')->whereHas('user', function ($query) use ($supervisorTerm, $supervisorDigits): void {
                $query->where('name', 'ilike', '%'.$supervisorTerm.'%');
                if ($supervisorDigits !== '') {
                    $query->orWhere('cpf', 'like', '%'.$supervisorDigits.'%');
                }
            })->orderBy('id')->limit(20)->get(),
            'selectedParty' => $selectedParty, 'selectedSupervisor' => $selectedSupervisor,
        ];
    }

    private function lookupKey(mixed $value): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }
        $key = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $key === false ? null : $key;
    }

    private function loadValues(): void
    {
        unset($this->record, $this->editableSections, $this->choices);
        $request = $this->record;
        $this->savedUpdatedAt = $request->getRawOriginal('updated_at');
        foreach (array_merge(...array_values(self::internshipSections())) as $field) {
            $value = $request->getAttribute($field);
            if ($value instanceof BackedEnum) {
                $value = $value->value;
            }
            if ($field === 'planned_start_date') {
                $value = $request->planned_start_date?->toDateString();
            }
            $this->values[$field] = $value ?? '';
        }
        $this->values['weekly_hours'] = array_replace(array_fill_keys(InternshipEndDateCalculator::DAYS, 0), $request->weekly_hours ?? []);
        $this->values['planned_pauses'] = $request->planned_pauses ?? [];
        $this->values['personal_data'] = app(InternshipRequestPersonalData::class)->values($request, $this->personal['owner']);
        $this->values['granting_party_mode'] = $request->granting_party_registration_request_id === null ? 'existing' : 'pending';
        $this->values['supervisor_mode'] = $request->supervisor_registration_request_id === null ? 'existing' : 'pending';
        $this->values['granting_party_change_note'] = $request->granting_party_snapshot['reported_changes'] ?? '';
        $this->partyCityId = $request->grantingParty?->address?->city_id ?? '';
        $party = $request->grantingPartyRegistrationRequest;
        $partyFields = ['document_type', 'document_number', 'name', 'street', 'number', 'neighborhood', 'uf', 'zip_code', 'representative_name', 'representative_role', 'phone', 'email', 'field_of_activity', 'professional_council', 'council_registration_number', 'credentialing_process_number'];
        $this->values['granting_party_proposal'] = array_replace(array_fill_keys($partyFields, ''), $party?->only($partyFields) ?? []);
        foreach ($this->values['granting_party_proposal'] as $field => $value) {
            if ($value instanceof BackedEnum) {
                $this->values['granting_party_proposal'][$field] = $value->value;
            }
        }
        $this->values['granting_party_proposal']['city_id'] = $party?->city_id ?? '';
        $supervisor = $request->supervisorRegistrationRequest;
        $supervisorFields = ['name', 'cpf', 'phone', 'email', 'job_role', 'has_academic_background', 'training_course', 'education_level', 'experience_time'];
        $this->values['supervisor_proposal'] = array_replace(array_fill_keys($supervisorFields, ''), $supervisor?->only($supervisorFields) ?? []);
        if (($this->values['supervisor_proposal']['education_level'] ?? null) instanceof BackedEnum) {
            $this->values['supervisor_proposal']['education_level'] = $this->values['supervisor_proposal']['education_level']->value;
        }
        $professionalFields = ['job_role'];
        $this->values['supervisor_professional'] = array_replace(array_fill_keys($professionalFields, ''), Arr::only($request->supervisor_snapshot ?? [], $professionalFields));
        if (($this->values['supervisor_mode'] ?? null) === 'pending' && filled($this->values['supervisor_proposal']['job_role'] ?? null)) {
            $this->values['supervisor_professional']['job_role'] = $this->values['supervisor_proposal']['job_role'];
        }
        $qualification = $this->values['supervisor_proposal']['has_academic_background'];
        $this->values['supervisor_proposal']['has_academic_background'] = $qualification === null || $qualification === '' ? '' : ((bool) $qualification ? '1' : '0');
        $this->values['is_remunerated'] = $request->is_remunerated === null ? '' : ($request->is_remunerated ? '1' : '0');
        $this->syncLegalCapacity();
    }

    #[Computed]
    public function isAdult(): ?bool
    {
        $birth = $this->values['personal_data']['birth_date'] ?? null;

        return \App\Helpers\DateHelper::isAdult(is_string($birth) ? $birth : null,
            $this->editableSections === [] ? $this->record->terms_accepted_at : null);
    }

    private function syncLegalCapacity(): void
    {
        if (! in_array('legal_capacity', $this->editableSections, true) && ! in_array('personal_data', $this->editableSections, true)) {
            return;
        }
        unset($this->isAdult);
        if ($this->isAdult === true) {
            $this->values['legal_capacity_declaration'] = 'adult';
        } elseif (($this->values['legal_capacity_declaration'] ?? '') === 'adult') {
            $this->values['legal_capacity_declaration'] = '';
        }
        if (($this->values['legal_capacity_declaration'] ?? '') !== 'minor') {
            foreach (['legal_guardian_name', 'legal_guardian_cpf', 'legal_guardian_kinship', 'legal_guardian_email'] as $field) {
                $this->values[$field] = '';
            }
        }
        if (($this->values['legal_capacity_declaration'] ?? '') !== 'emancipated_minor') {
            $this->evidence = null;
        }
    }

    public function updatedPartyCityId(): void
    {
        $this->values['granting_party_id'] = '';
        $this->values['granting_party_change_note'] = '';
        $this->partyMatchMessage = '';
        $this->termsAccepted = false;
        unset($this->choices);
        $this->resetValidation('granting_party_id');
    }

    public function updatedPartySearch(): void
    {
        $this->partyMatchMessage = '';
    }

    public function updatedValues(mixed $value, string $key): void
    {
        if (in_array($key, ['personal_data.birth_date', 'legal_capacity_declaration'], true)) {
            $this->syncLegalCapacity();
            $this->resetValidation('legal_capacity_declaration');
        }
        if ($key === 'internship_type_id') {
            $type = $this->choices['types']->firstWhere('id', (int) $value);
            if (! array_key_exists($this->values['student_year_semester'] ?? '', $type?->academicPeriodOptions() ?? [])) {
                $this->values['student_year_semester'] = null;
            }
            $this->resetValidation('student_year_semester');
        }
        if ($key === 'granting_party_proposal.document_type') {
            $this->values['granting_party_proposal']['document_number'] = '';
            $hidden = ['field_of_activity', 'professional_council', 'council_registration_number', 'credentialing_process_number'];
            $hidden = [...$hidden, ...($value === 'cnpj' ? ['street', 'number', 'neighborhood', 'zip_code'] : ['representative_name', 'representative_role'])];
            foreach ($hidden as $field) {
                $this->values['granting_party_proposal'][$field] = '';
            }
            $this->cancelCepLookup();
            $this->resetValidation();
        }
        if ($key === 'granting_party_id') {
            $this->values['granting_party_change_note'] = '';
            $this->partyMatchMessage = '';
        }
        if ($key === 'supervisor_affiliation_id') {
            $this->supervisorMatchMessage = '';
            unset($this->choices);
            $profile = $this->choices['selectedSupervisor']?->user?->personalData;
            $this->values['supervisor_professional'] = ['job_role' => $profile?->job_role ?? ''];
        }
        if ($key === 'supervisor_mode' && $value === 'pending') {
            $this->values['supervisor_proposal']['job_role'] = $this->values['supervisor_professional']['job_role'] ?? null;
        }
        if ($key === 'supervisor_professional.job_role' && ($this->values['supervisor_mode'] ?? null) === 'pending') {
            $this->values['supervisor_proposal']['job_role'] = $value;
        }
        $this->termsAccepted = false;
        if (in_array($key, ['granting_party_proposal.document_number', 'supervisor_proposal.cpf'], true)) {
            $kind = $key === 'supervisor_proposal.cpf' ? 'supervisor' : 'granting_party';
            $this->resetValidation($key);
            $number = data_get($this->values, $key);
            if (filled($number)) {
                $rule = $kind === 'supervisor' ? 'cpf' : ($this->values['granting_party_proposal']['document_type'] ?? 'cpf_ou_cnpj');
                $validator = Validator::make(['document' => $number], ['document' => ['required', in_array($rule, ['cpf', 'cnpj'], true) ? $rule : 'cpf_ou_cnpj']],
                    attributes: ['document' => $kind === 'supervisor' ? 'CPF do supervisor' : 'CPF ou CNPJ']);
                if ($validator->fails()) {
                    $this->addError($key, $validator->errors()->first('document'));
                } else {
                    $this->resolveExistingRegistration($kind);
                }
            }
        }
    }

    public function completeRegistration(string $kind): void
    {
        $this->authorizeRegistrationSection($kind);
        $this->values[$kind.'_mode'] = 'pending';
        $this->values[$kind === 'granting_party' ? 'granting_party_id' : 'supervisor_affiliation_id'] = '';
        $term = trim($kind === 'granting_party' ? $this->partySearch : $this->supervisorSearch);
        $proposal = $this->values[$kind.'_proposal'];
        $this->partyMatchMessage = '';
        $this->supervisorMatchMessage = '';
        if ($term !== '' && Validator::make(['document' => $term], ['document' => [$kind === 'supervisor' ? 'cpf' : 'cpf_ou_cnpj']])->passes()) {
            if ($kind === 'granting_party') {
                $proposal['document_type'] = mb_strlen(BrazilianDocumentHelper::normalizeCnpj($term)) === 11 ? 'cpf' : 'cnpj';
                $proposal['document_number'] = $term;
            } else {
                $proposal['cpf'] = $term;
            }
        } elseif (blank($proposal['name'] ?? null) && $term !== '' && DigitsHelper::only($term) === '') {
            $proposal['name'] = $term;
        }
        if ($kind === 'supervisor') {
            $proposal['job_role'] = $this->values['supervisor_professional']['job_role'] ?? '';
        }
        $this->values[$kind.'_proposal'] = $proposal;
        $this->termsAccepted = false;
        $this->resetValidation();
        $this->resolveExistingRegistration($kind);
    }

    public function searchRegistration(string $kind): void
    {
        $this->authorizeRegistrationSection($kind);
        $this->values[$kind.'_mode'] = 'existing';
        $this->termsAccepted = false;
        $this->resetValidation();
        unset($this->choices);
    }

    public function changeRegistration(string $kind): void
    {
        $this->authorizeRegistrationSection($kind);
        $this->values[$kind.'_mode'] = 'existing';
        $this->values[$kind === 'granting_party' ? 'granting_party_id' : 'supervisor_affiliation_id'] = '';
        $this->values[$kind.'_proposal'] = array_fill_keys(array_keys($this->values[$kind.'_proposal']), '');
        if ($kind === 'granting_party') {
            $this->partySearch = '';
            $this->partyCityId = '';
            $this->partyMatchMessage = '';
            $this->values['granting_party_change_note'] = '';
        } else {
            $this->supervisorSearch = '';
            $this->supervisorMatchMessage = '';
        }
        $this->termsAccepted = false;
        $this->resetValidation();
        unset($this->choices);
    }

    public function clearPartyCityFilter(): void
    {
        $this->authorizeRegistrationSection('granting_party');
        $this->partyCityId = '';
        unset($this->choices);
    }

    private function authorizeRegistrationSection(string $kind): void
    {
        abort_unless(in_array($kind, ['granting_party', 'supervisor'], true), 404);
        Gate::authorize('update', $this->record);
        abort_unless(in_array($kind, $this->editableSections, true), 403);
    }

    private function resolveExistingRegistration(?string $onlyKind = null): bool
    {
        $lookup = app(InternshipRegistrationLookup::class);
        foreach ($onlyKind === null ? ['granting_party', 'supervisor'] : [$onlyKind] as $kind) {
            if (! in_array($kind, $this->editableSections, true) || ($this->values[$kind.'_mode'] ?? '') !== 'pending') {
                continue;
            }
            $this->authorizeRegistrationSection($kind);
            $field = $kind === 'granting_party' ? 'granting_party_proposal.document_number' : 'supervisor_proposal.cpf';
            $document = data_get($this->values, $field);
            if (! is_string($document) || Validator::make(['document' => $document], ['document' => ['required', $kind === 'supervisor' ? 'cpf' : 'cpf_ou_cnpj']])->fails()) {
                continue;
            }
            if ($kind === 'granting_party') {
                $matches = $lookup->grantingPartyMatches($this->personal['owner'], $document);
                if ($matches->isEmpty()) {
                    continue;
                }
                $this->partySearch = BrazilianDocumentHelper::normalizeCnpj($document);
                $this->partyCityId = '';
                $this->values['granting_party_id'] = $matches->count() === 1 ? $matches->first()->id : '';
                $this->values['granting_party_change_note'] = '';
                $this->partyMatchMessage = $matches->count() === 1
                    ? 'Encontramos esta concedente e já a selecionamos. Confira os dados abaixo; não é necessário solicitar outro cadastro.'
                    : 'Encontramos mais de uma unidade com este CPF/CNPJ. Escolha abaixo a unidade onde fará o estágio.';
            } else {
                $supervisor = $lookup->supervisorMatch($this->personal['owner'], $document);
                if ($supervisor === null) {
                    continue;
                }
                $this->supervisorSearch = '';
                $this->values['supervisor_affiliation_id'] = $supervisor->id;
                if (blank($this->values['supervisor_professional']['job_role'] ?? null)) {
                    $this->values['supervisor_professional']['job_role'] = $supervisor->user->personalData?->job_role ?? '';
                }
                $this->supervisorMatchMessage = 'Encontramos um vínculo ativo de supervisor para este CPF e já o selecionamos. Confira os dados e o cargo ou função; não é necessário solicitar outro cadastro.';
            }
            $this->values[$kind.'_mode'] = 'existing';
            $this->matchedRegistrationStep = $kind === 'granting_party' ? 2 : 3;
            $this->step = $this->matchedRegistrationStep;
            $this->navigationStep = $this->step;
            $this->termsAccepted = false;
            $this->cancelCepLookup();
            $this->resetValidation();
            unset($this->choices);
            return true;
        }
        return false;
    }

    protected function addressLookupPath(): string
    {
        return $this->step === 0 ? 'personal_data.address' : 'granting_party_proposal';
    }

    protected function authorizeAddressLookup(): void
    {
        Gate::authorize('update', $this->record);
        abort_unless(($this->step === 0 && in_array('personal_data', $this->editableSections, true))
            || (in_array($this->step, [2], true) && in_array('granting_party', $this->editableSections, true) && ($this->values['granting_party_mode'] ?? '') === 'pending'), 403);
    }

    private function payload(?string $section = null): array
    {
        $fields = [];
        foreach ($this->editableSections as $editable) {
            if ($section === null || $section === $editable || ($section === 'schedule' && $editable === 'planned_start_date')) {
                $fields = [...$fields, ...(self::internshipSections()[$editable] ?? [])];
            }
        }
        $payload = Arr::only($this->values, $fields);
        if (is_array($payload['granting_party_proposal'] ?? null) && array_key_exists('city_id', $payload['granting_party_proposal'])) {
            $cityId = $this->lookupKey($payload['granting_party_proposal']['city_id']);
            $city = $cityId === null ? null : City::query()->find($cityId);
            $payload['granting_party_proposal']['uf'] = $city?->state->value;
        }
        foreach (['granting_party', 'supervisor'] as $kind) {
            if (($payload[$kind.'_mode'] ?? null) !== 'pending') {
                unset($payload[$kind.'_proposal']);
                if ($kind === 'supervisor' && isset($payload['supervisor_professional'])) {
                    $payload['supervisor_professional'] = Arr::only($payload['supervisor_professional'], ['job_role']);
                }
            } else {
                unset($payload[$kind === 'granting_party' ? 'granting_party_id' : 'supervisor_affiliation_id']);
                if ($kind === 'supervisor') {
                    $payload['supervisor_proposal']['job_role'] = Arr::get($payload, 'supervisor_professional.job_role');
                    unset($payload['supervisor_professional']);
                }
            }
        }
        if (($payload['legal_capacity_declaration'] ?? null) !== 'minor') {
            foreach (['legal_guardian_name', 'legal_guardian_cpf', 'legal_guardian_kinship', 'legal_guardian_email'] as $field) {
                unset($payload[$field]);
            }
        }
        if (array_key_exists('is_remunerated', $payload) && $payload['is_remunerated'] !== null && $payload['is_remunerated'] !== '' && ! (bool) $payload['is_remunerated']) {
            unset($payload['grant_value'], $payload['transportation_allowance']);
        }
        return $payload;
    }

    private function persist(?string $section = null, string $operation = 'save'): bool
    {
        if ($operation !== 'withdraw' && ($this->matchedRegistrationStep !== null || $this->resolveExistingRegistration($section))) {
            return false;
        }
        $this->syncLegalCapacity();
        try {
            if ($operation !== 'withdraw' && ($section === null || $section === 'legal_capacity')
                && ($this->values['legal_capacity_declaration'] ?? '') === 'emancipated_minor'
                && $this->evidence !== null && ! $this->uploadEvidence(app(StoreEmancipationEvidence::class))) {
                return false;
            }
            $saved = app(ManageStudentInternshipRequest::class)->handle(Auth::user(), $this->affiliationId, $this->record, $operation,
                $operation === 'withdraw' ? ['current_password' => $this->withdrawalPassword] : $this->payload($section), $this->termsAccepted, $this->savedUpdatedAt);
            $this->savedUpdatedAt = $saved->getRawOriginal('updated_at');
            unset($this->record, $this->editableSections);
            $this->resetValidation();
            $this->dispatch('form-saved', scope: 'internship-request', savedAt: formatDateTime(now()));
            return true;
        } catch (ValidationException $exception) {
            if ($operation !== 'withdraw' && $this->resolveExistingRegistration($section)) {
                return false;
            }
            $this->setErrorBag($exception->errors());
            if ($operation !== 'withdraw' && ! in_array(array_key_first($exception->errors()), ['request', 'snapshot'], true)) {
                $this->step = $this->stepForError(array_key_first($exception->errors()));
                $this->navigationStep = $this->step;
            }
            return false;
        } catch (\Illuminate\Database\QueryException $exception) {
            \Illuminate\Support\Facades\Log::error('Falha ao salvar solicitação de estágio.', ['internship_request_id' => $this->requestId, 'error_code' => $exception->getCode()]);
            $this->addError('request', $exception->getCode() === '42703' ? 'O banco de dados está desatualizado. Aplique a estrutura das migrations para salvar o pedido de cadastro. Seu preenchimento continua nesta tela.' : 'Não foi possível salvar agora. Seu preenchimento continua nesta tela. Tente novamente.');
            return false;
        }
    }

    public function save(): void
    {
        if ($this->persist()) {
            session()->flash('status', 'Preenchimento salvo. Você pode continuar depois.');
            $this->redirectRoute('internship-requests.index', navigate: true);
        }
    }

    public function goTo(int $step): void
    {
        abort_unless($step >= 0 && $step < count(self::STEPS), 404);
        if (($this->matchedRegistrationStep !== null && $step > $this->matchedRegistrationStep)
            || ($step > $this->step && $this->resolveExistingRegistration())) {
            return;
        }
        if ($step > $this->step) {
            foreach (array_slice(self::STEPS, 0, $step, true) as $index => $item) {
                try {
                    $this->validateSection($item['section']);
                } catch (ValidationException $exception) {
                    $this->step = $index;
                    $this->navigationStep = $index;
                    $this->setErrorBag($exception->errors());
                    return;
                }
            }
        }
        $section = self::STEPS[$this->step]['section'];
        if ($step > $this->step && (in_array($section, $this->editableSections, true) || ($section === 'schedule' && in_array('planned_start_date', $this->editableSections, true))) && ! $this->persist()) {
            return;
        }
        $this->step = $step;
        $this->navigationStep = $step;
        $this->cancelCepLookup();
        $this->cityLookupMessage = '';
        $this->resetValidation();
    }

    private function validateSection(string $section): void
    {
        $this->syncLegalCapacity();
        $editable = $this->editableSections;
        if (! in_array($section, $editable, true) && ! ($section === 'schedule' && in_array('planned_start_date', $editable, true))) {
            return;
        }
        $data = $this->normalizeInternshipInput($this->values);
        if ($section === 'personal_data') {
            app(InternshipRequestPersonalData::class)->validateComplete($data['personal_data'], $this->personal['owner']);
            return;
        }
        if ($section === 'supervisor') {
            $choice = $data['supervisor_proposal']['has_academic_background'] ?? null;
            if (($data['supervisor_mode'] ?? null) === 'pending' && $choice !== null) {
                foreach ((bool) $choice ? ['experience_time'] : ['training_course', 'education_level'] as $field) {
                    unset($data['supervisor_proposal'][$field]);
                }
            }
            Validator::make($data, $this->supervisorRequestStepRules($data), attributes: $this->internshipFieldLabels())->validate();
            return;
        }
        $required = match ($section) {
            'internship' => ['internship_type_id', 'student_year_semester', 'advisor_affiliation_id'],
            'granting_party' => ($data['granting_party_mode'] ?? null) === 'pending'
                ? array_map(fn (string $field): string => 'granting_party_proposal.'.$field, [
                    'document_type', 'document_number', 'name', 'phone', 'email', 'city_id',
                    ...(($data['granting_party_proposal']['document_type'] ?? null) === 'cnpj' ? ['representative_name', 'representative_role'] : ['zip_code', 'street', 'number', 'neighborhood']),
                ]) : ['granting_party_id'],
            'activities' => ['internship_sector', 'activities'],
            'legal_capacity' => ['legal_capacity_declaration', ...(($data['legal_capacity_declaration'] ?? null) === 'minor'
                ? ['legal_guardian_name', 'legal_guardian_cpf', 'legal_guardian_kinship', 'legal_guardian_email'] : [])],
            'schedule' => [...(in_array('schedule', $editable, true) ? ['weekly_hours', ...array_map(fn (string $day): string => 'weekly_hours.'.$day, InternshipEndDateCalculator::DAYS)] : []),
                ...(in_array('planned_start_date', $editable, true) ? ['planned_start_date'] : [])],
            'remuneration' => ['is_remunerated', ...((bool) ($data['is_remunerated'] ?? false) ? ['grant_value'] : [])],
            default => [],
        };
        $rules = $this->internshipInputRules();
        $completeRules = [];
        foreach ($required as $field) {
            $completeRules[$field] = ['required', ...array_filter($rules[$field] ?? ($rules['weekly_hours.*'] ?? []), fn (mixed $rule): bool => ! in_array($rule, ['sometimes', 'nullable'], true))];
        }
        if ($section === 'schedule' && in_array('planned_start_date', $editable, true)) {
            $completeRules['planned_start_date'][] = 'after_or_equal:'.now(config('app.timezone'))->addDays(7)->toDateString();
        }
        Validator::make($data, $completeRules, ['planned_start_date.after_or_equal' => 'Escolha uma data com pelo menos sete dias corridos de antecedência.'], $this->internshipFieldLabels())->validate();
        if ($section === 'internship') {
            Validator::make($data, ['advisor_affiliation_id' => ['required', \Illuminate\Validation\Rule::in($this->choices['advisors']->pluck('id')->all())]],
                ['advisor_affiliation_id.in' => 'Selecione um orientador ativo do seu campus.'], $this->internshipFieldLabels())->validate();
            $type = $this->choices['types']->firstWhere('id', (int) ($data['internship_type_id'] ?? 0));
            Validator::make($data, ['student_year_semester' => ['required', \Illuminate\Validation\Rule::in(array_keys($type?->academicPeriodOptions() ?? []))]],
                ['student_year_semester.in' => 'Selecione um ano ou semestre permitido para este tipo de estágio.'])->validate();
        }
        if ($section === 'schedule') {
            $type = $this->choices['types']->firstWhere('id', (int) ($data['internship_type_id'] ?? 0));
            $hours = $data['weekly_hours'] ?? [];
            $total = array_sum($hours);
            if (in_array('schedule', $editable, true) && ($type === null || $total < 1 || $total > $type->max_weekly_hours || max($hours) > $type->max_daily_hours)) {
                throw ValidationException::withMessages(['weekly_hours' => 'Informe uma carga positiva e respeite os limites diários e semanais do tipo de estágio.']);
            }
            app(InternshipPlannedPauses::class)->validate($data['planned_pauses'] ?? [], $data['planned_start_date'] ?? null, complete: true);
        }
        if ($section === 'legal_capacity') {
            if ($this->isAdult === null || $this->isAdult !== (($data['legal_capacity_declaration'] ?? '') === 'adult')) {
                throw ValidationException::withMessages(['legal_capacity_declaration' => 'Confira sua situação e a data de nascimento nos dados pessoais.']);
            }
            if (($data['legal_capacity_declaration'] ?? '') === 'emancipated_minor') {
                if ($this->evidence !== null) {
                    Validator::make(['evidence' => $this->evidence], StoreEmancipationEvidence::validationRules(), attributes: ['evidence' => 'comprovante de emancipação'])->validate();
                } else {
                    $latest = $this->record->emancipationEvidences->first();
                    if ($latest === null || in_array($latest->status, [EmancipationEvidenceStatus::Returned, EmancipationEvidenceStatus::Cancelled], true) || ! $latest->hasMedia('emancipation_evidence')) {
                        throw ValidationException::withMessages(['evidence' => 'Anexe o comprovante de emancipação para continuar.']);
                    }
                }
            }
        }
    }

    public function addPlannedPause(): void
    {
        Gate::authorize('update', $this->record);
        abort_unless(in_array('schedule', $this->editableSections, true), 403);
        if (count($this->values['planned_pauses'] ?? []) >= InternshipPlannedPauses::MAX_PERIODS) {
            return;
        }
        $this->values['planned_pauses'][(string) Str::uuid()] = ['starts_at' => '', 'ends_at' => '', 'reason' => ''];
        $this->termsAccepted = false;
    }

    public function removePlannedPause(string $key): void
    {
        Gate::authorize('update', $this->record);
        abort_unless(in_array('schedule', $this->editableSections, true), 403);
        unset($this->values['planned_pauses'][$key]);
        $this->termsAccepted = false;
        $this->resetValidation();
    }

    public function applyWeekdayHours(): void
    {
        Gate::authorize('update', $this->record);
        abort_unless(in_array('schedule', $this->editableSections, true), 403);
        $hours = $this->values['weekly_hours']['monday'] ?? '';
        foreach (InternshipEndDateCalculator::DAYS as $day) {
            $this->values['weekly_hours'][$day] = in_array($day, ['saturday', 'sunday'], true) ? 0 : $hours;
        }
        $this->termsAccepted = false;
        $this->resetValidation('weekly_hours');
    }

    public function updatedNavigationStep(int $step): void
    {
        $this->goTo($step);
        $this->navigationStep = $this->step;
    }

    public function submit(): void
    {
        if (! $this->persist()) {
            return;
        }
        if ($this->persist(operation: 'submit')) {
            $this->termsAccepted = false;
            \Flux\Flux::toast('Solicitação enviada para análise.', variant: 'success');
        }
    }

    public function uploadEvidence(StoreEmancipationEvidence $action): bool
    {
        if ($this->evidence === null) {
            $this->addError('evidence', 'Escolha um comprovante em PDF, JPG ou PNG.');
            return false;
        }
        try {
            $action->handle(Auth::user(), $this->affiliationId, $this->record, $this->evidence);
            $this->reset('evidence');
            unset($this->record);
            return true;
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
            return false;
        }
    }

    public function updatedEvidence(): void
    {
        $this->termsAccepted = false;
        $this->resetValidation('evidence');
    }

    public function withdraw(): void
    {
        Gate::authorize('withdraw', $this->record);
        if ($this->persist(operation: 'withdraw')) {
            $this->showWithdrawal = false;
            $this->step = 8;
            $this->navigationStep = 8;
            \Flux\Flux::toast('Desistência registrada.', variant: 'success');
        }
        $this->withdrawalPassword = '';
    }

    public function openWithdrawal(): void
    {
        Gate::authorize('withdraw', $this->record);
        $this->reset('withdrawalPassword');
        $this->resetValidation('current_password');
        $this->showWithdrawal = true;
    }

    public function closeWithdrawal(): void
    {
        $this->reset('withdrawalPassword', 'showWithdrawal');
        $this->resetValidation('current_password');
    }

    private function stepForError(string $field): int
    {
        if ($field === 'projected_end_date') {
            return 6;
        }
        if (str_starts_with($field, 'personal_data') || str_starts_with($field, 'student_') || in_array($field, ['name', 'cpf', 'email', 'registration_number', 'rg', 'rg_issuer', 'rg_issue_date', 'birth_date', 'phone', 'address_id'], true)) {
            return $field === 'student_year_semester' ? 1 : 0;
        }
        if ($field === 'evidence' || str_starts_with($field, 'evidence.')) {
            return 5;
        }
        if (str_starts_with($field, 'granting_party_proposal.')) {
            return 2;
        }
        if ($field === 'job_role' || str_starts_with($field, 'supervisor_professional.') || str_starts_with($field, 'supervisor_proposal.')) {
            return 3;
        }
        foreach (self::internshipSections() as $section => $fields) {
            if (in_array(explode('.', $field)[0], $fields, true)) {
                return $section === 'planned_start_date' ? 6 : (int) array_search($section, array_column(self::STEPS, 'section'));
            }
        }
        return 8;
    }
}; ?>

@php
    $request = $this->record;
    $personal = $this->personal;
    $officeEmail = $personal['owner']->campus->internship_office_email;
    $choices = $this->choices;
    $currentSection = self::STEPS[$step]['section'];
    $canEdit = in_array($currentSection, $this->editableSections, true) || ($currentSection === 'schedule' && in_array('planned_start_date', $this->editableSections, true));
    $pendingParty = ($values['granting_party_mode'] ?? '') === 'pending';
    $pendingSupervisor = ($values['supervisor_mode'] ?? '') === 'pending';
    $canUpdateRequest = auth()->user()->can('update', $request);
@endphp

<div class="w-full space-y-8" data-unsaved-changes="internship-request" data-unsaved-models='["values", "evidence"]' data-unsaved-actions='["save", "goTo", "submit", "withdraw"]' data-unsaved-enabled="{{ $canUpdateRequest ? 'true' : 'false' }}">
    @if ($canUpdateRequest)<x-form-save-status :saved-at="$request->updated_at" />@endif
    @if (session('status'))<div hidden x-data x-init="$nextTick(() => $flux.toast(@js(session('status')), { variant: 'success' }))"></div>@endif
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('internship-requests.index')" wire:navigate>Minhas solicitações</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Solicitação nº {{ $request->id }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex min-w-0 items-start gap-3">
            <flux:avatar icon="clipboard-document-list" icon-variant="outline" class="shrink-0 bg-brand/10 text-brand [&>svg]:opacity-100" />
            <div><flux:heading level="1" size="xl">{{ $canUpdateRequest ? 'Vamos preparar seu estágio' : 'Minha solicitação de estágio' }}</flux:heading><flux:text class="mt-2">{{ $canUpdateRequest ? 'Preencha por etapas. Você pode salvar e continuar depois.' : 'Confira os dados enviados e acompanhe a situação da solicitação.' }}</flux:text></div>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <flux:badge>{{ $request->status->label() }}</flux:badge>
            <flux:button variant="outline" icon="arrow-left" :href="route('internship-requests.index')" wire:navigate>Voltar</flux:button>
            @can('withdraw', $request)
                <flux:button variant="danger" wire:click="openWithdrawal">Desistir da solicitação</flux:button>
            @endcan
        </div>
    </div>
    @if (filled($officeEmail))
        <flux:callout icon="information-circle" heading="Dúvidas sobre a solicitação?">
            <flux:callout.text>
                Se tiver dúvidas ou problemas no preenchimento, entre em contato com o Setor de Estágio pelo e-mail:
                <x-copy-email :email="$officeEmail" />
            </flux:callout.text>
        </flux:callout>
    @endif
    @if ($request->status === InternshipRequestStatus::PendingCorrection)
        @php($correction = $request->corrections->firstWhere('status', InternshipRequestCorrectionStatus::Open))
        <flux:callout variant="danger" icon="exclamation-triangle" heading="O Setor solicitou correções nesta solicitação">
            <flux:callout.text class="text-base!">Leia as orientações de cada etapa e ajuste os campos correspondentes antes de reenviar.</flux:callout.text>
            @if ($correction?->section_messages)
                <div class="mt-4 space-y-3">
                    @foreach ($correction->affected_sections as $section)
                        @if (filled($correction->section_messages[$section] ?? null))
                            <div wire:key="correction-reason-{{ $section }}" class="rounded-lg border border-red-300 bg-white/70 p-4 dark:border-red-900 dark:bg-zinc-950/40">
                                <flux:heading level="4" size="base">{{ \App\Models\InternshipRequestCorrection::sectionLabel($section) }}</flux:heading>
                                <flux:text class="mt-2 whitespace-pre-line text-base!">{{ $correction->section_messages[$section] }}</flux:text>
                            </div>
                        @endif
                    @endforeach
                    @if (collect($correction->affected_sections)->contains(fn ($section) => blank($correction->section_messages[$section] ?? null)) && filled($correction->message))
                        <flux:callout.text class="mt-3 whitespace-pre-line text-base!">{{ $correction->message }}</flux:callout.text>
                    @endif
                    @if (filled($correction->general_message))
                        <div class="rounded-lg border border-red-300 bg-white/70 p-4 dark:border-red-900 dark:bg-zinc-950/40">
                            <flux:heading level="4" size="base">Orientação geral do Setor</flux:heading>
                            <flux:text class="mt-2 whitespace-pre-line text-base!">{{ $correction->general_message }}</flux:text>
                        </div>
                    @endif
                </div>
            @else
                <flux:callout.text class="mt-3 whitespace-pre-line text-base!">{{ $correction?->message }}</flux:callout.text>
            @endif
            <flux:callout.text class="mt-3">Somente as etapas indicadas estão liberadas. Depois do reenvio, a solicitação volta para análise e mantém o mesmo número.</flux:callout.text>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($this->editableSections as $section)
                    <flux:button size="sm" wire:key="correct-{{ $section }}" wire:click="goTo({{ $section === 'planned_start_date' ? 6 : (int) array_search($section, array_column(self::STEPS, 'section')) }})">Corrigir {{ self::SECTION_LABELS[$section] ?? $section }}</flux:button>
                @endforeach
            </div>
        </flux:callout>
    @elseif (! $canUpdateRequest)
        <flux:callout heading="{{ match ($request->status->value) { 'accepted' => 'Solicitação aceita: aguarde a documentação e a liberação para começar.', 'rejected' => 'Solicitação recusada pelo Setor.', 'withdrawn' => 'Desistência registrada.', default => 'Solicitação enviada: aguarde a análise do Setor.' } }}">
            <flux:callout.text>Os dados enviados estão disponíveis para consulta.</flux:callout.text>
        </flux:callout>
    @endif
    @if ($request->status === InternshipRequestStatus::Rejected)
        <flux:callout variant="warning">{{ $request->decision_reason }}</flux:callout>
    @endif

    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:text class="font-medium text-brand!">Etapa {{ $step + 1 }} de {{ count(self::STEPS) }} · {{ self::STEPS[$step]['title'] }}</flux:text>
            <flux:text size="sm">O envio para análise acontece somente na revisão final.</flux:text>
        </div>
        <flux:progress wire:key="request-progress-{{ $step }}" :value="$step + 1" :max="count(self::STEPS)" :aria-valuenow="$step + 1" :aria-valuemax="count(self::STEPS)" style="--flux-progress-color: var(--color-brand)" aria-label="Etapa atual da solicitação" />
    </div>

    <div class="grid items-start gap-8 lg:grid-cols-[15rem_minmax(0,1fr)]">
        <nav aria-label="Etapas da solicitação" class="lg:sticky lg:top-6">
            <div class="lg:hidden">
                <x-select label="Ir para uma etapa" wire:model.live="navigationStep" :value="$navigationStep" :options="collect(self::STEPS)->mapWithKeys(fn ($item, $index) => [$index => ($index + 1).'. '.$item['title']])->all()" />
            </div>
            <ol class="hidden space-y-1 lg:block">
                @foreach (self::STEPS as $index => $item)
                    <li wire:key="step-{{ $index }}">
                        <flux:button variant="ghost" wire:click="goTo({{ $index }})" wire:loading.attr="disabled" :aria-current="$step === $index ? 'step' : null"
                            @class(['h-auto! w-full justify-start! whitespace-normal! py-2.5! text-left', 'bg-brand/10! text-brand!' => $step === $index])>
                            <span class="flex items-center gap-3">
                                <span class="flex size-6 shrink-0 items-center justify-center rounded-full border border-current/20 text-xs">{{ $index + 1 }}</span>
                                <span>{{ $item['title'] }}{{ $request->status === InternshipRequestStatus::PendingCorrection && in_array($item['section'], $this->editableSections, true) ? ' · Corrigir' : '' }}</span>
                            </span>
                        </flux:button>
                    </li>
                @endforeach
            </ol>
        </nav>

        <div class="min-w-0 space-y-6">
    @if ($errors->any() && ! $showWithdrawal)
        <div hidden x-data x-init="$nextTick(() => $flux.toast({ heading: 'Revise os campos indicados', text: 'Seu preenchimento foi mantido. Complete os campos desta etapa ou salve o rascunho para continuar depois.', variant: 'danger', duration: 12000 }))"></div>
    @endif
    <flux:error name="request" />
    <flux:error name="snapshot" />

    <section wire:key="stage-{{ $step }}" aria-labelledby="stage-title" class="space-y-6">
        <flux:heading id="stage-title" tabindex="-1" class="outline-none" x-data x-init="$el.focus({ preventScroll: true })" level="2" size="lg">{{ self::STEPS[$step]['title'] }}</flux:heading>
        @if ($step !== 8 && ! $canEdit)
            <flux:text>Esta seção está disponível apenas para consulta. Veja os dados enviados na revisão.</flux:text>
            <flux:button wire:click="goTo(8)">Ver dados enviados</flux:button>
        @else
            @include('pages.internship-requests.partials.steps')
        @endif
    </section>

    <flux:separator />
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:button icon="arrow-left" wire:click="goTo({{ max(0, $step - 1) }})" :disabled="$step === 0" wire:loading.attr="disabled">Etapa anterior</flux:button>
        <div class="flex flex-wrap items-center gap-3">
            <span wire:loading wire:target="save,goTo,submit,withdraw" role="status" class="text-sm text-zinc-500">Salvando…</span>
            @can('update', $request)<flux:button wire:click="save" wire:loading.attr="disabled">Salvar e continuar depois</flux:button>@endcan
            @if ($step < 8)<flux:button variant="primary" icon:trailing="arrow-right" wire:click="goTo({{ $step + 1 }})" wire:loading.attr="disabled">{{ $canEdit ? ($step === 1 ? 'Confirmar e continuar' : 'Salvar e continuar') : 'Próxima etapa' }}</flux:button>@endif
        </div>
    </div>
    <flux:text size="sm">Última gravação: {{ formatDateTime($request->updated_at) }}. @can('update', $request)Você pode voltar para revisar sem perder o preenchimento desta tela. Use “Salvar e continuar depois” para guardar tudo antes de sair.@endcan</flux:text>
        </div>
    </div>
    @can('withdraw', $request)
        <flux:modal name="withdraw-internship-request" wire:model.self="showWithdrawal" wire:close="closeWithdrawal" class="md:w-md">
            <form wire:submit="withdraw" class="space-y-6">
                <div>
                    <flux:heading size="lg" level="2">Desistir da solicitação nº {{ $request->id }}</flux:heading>
                    <flux:text class="mt-2">A solicitação será encerrada e preservada no histórico. Confirme sua senha para registrar a desistência.</flux:text>
                </div>
                <flux:input wire:model="withdrawalPassword" error:name="current_password" label="Sua senha atual" type="password" autocomplete="current-password" required viewable />
                <div class="flex flex-wrap justify-end gap-3">
                    <flux:modal.close><flux:button type="button" variant="outline" wire:click="closeWithdrawal">Cancelar</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="withdraw">Confirmar desistência</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</div>
