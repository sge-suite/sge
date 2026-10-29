
@if ($request->internship_id !== null)
    <flux:callout icon="check-circle" :heading="'Estágio nº '.$request->internship_id">
        <flux:callout.text>A solicitação está vinculada ao estágio. Situação: {{ $request->internship->status->label() }}.</flux:callout.text>
    </flux:callout>
@endif
<flux:text>Revise as escolhas antes de enviar. O Setor analisará a proposta e os pedidos de cadastro. Você só poderá iniciar o estágio após a documentação e a liberação.</flux:text>
@php
    $sent = $request->terms_accepted_at !== null;
    $editingCorrection = $request->status->value === 'pending_correction' && auth()->user()->can('update', $request);
    $editableSections = $editingCorrection ? $this->editableSections : [];
    $studentData = $sent && !in_array('personal_data', $editableSections, true) ? ($request->student_snapshot ?? []) : [
        'name' => $personal['user']->name, 'cpf' => $personal['user']->cpf, 'email' => $personal['owner']->email,
        'registration_number' => $personal['owner']->registration_number,
    ] + ($values['personal_data'] ?? []);
    $partyData = $sent && !in_array('granting_party', $editableSections, true) ? ($request->granting_party_snapshot ?? []) : ($pendingParty ? ($values['granting_party_proposal'] ?? []) : ($choices['selectedParty']?->only(['name', 'document_number', 'phone', 'email', 'representative_name', 'representative_role', 'field_of_activity', 'professional_council', 'council_registration_number', 'credentialing_process_number']) ?? []));
    $supervisorData = $sent && !in_array('supervisor', $editableSections, true) ? ($request->supervisor_snapshot ?? []) : ($pendingSupervisor ? array_replace($values['supervisor_proposal'] ?? [], ['job_role' => $values['supervisor_professional']['job_role'] ?? null]) : (['name' => $choices['selectedSupervisor']?->user?->name, 'email' => $choices['selectedSupervisor']?->email, 'phone' => $choices['selectedSupervisor']?->user?->personalData?->phone] + ($values['supervisor_professional'] ?? [])));
    $studentAddress = $sent && !in_array('personal_data', $editableSections, true) ? $request->studentAddress : new \App\Models\Address($values['personal_data']['address'] ?? []);
    $workplaceAddress = $sent && !in_array('granting_party', $editableSections, true) ? $request->workplaceAddress : $choices['selectedParty']?->address;
    $missing = [];
    foreach (['name' => 'Nome', 'cpf' => 'CPF', 'email' => 'E-mail', 'registration_number' => 'Matrícula', 'rg' => 'RG', 'rg_issuer' => 'Órgão emissor', 'rg_issue_date' => 'Emissão do RG', 'birth_date' => 'Nascimento', 'phone' => 'Telefone'] as $key => $label) {
        if (blank($studentData[$key] ?? null)) { $missing[] = $label.' nos dados pessoais'; }
    }
    if ($studentAddress === null || collect($studentAddress->only(['city_id', 'street', 'number', 'neighborhood', 'zip_code']))->contains(fn ($value) => blank($value))) { $missing[] = 'Endereço completo nos dados pessoais'; }
    foreach (['internship_type_id' => 'Tipo de estágio', 'student_year_semester' => 'Ano/semestre', 'advisor_affiliation_id' => 'Orientador', 'activities' => 'Atividades', 'internship_sector' => 'Setor/área', 'legal_capacity_declaration' => 'Capacidade civil', 'planned_start_date' => 'Data de início', 'is_remunerated' => 'Escolha sobre remuneração'] as $key => $label) {
        if (blank($values[$key] ?? null)) { $missing[] = $label; }
    }
    if (blank($partyData['name'] ?? null)) { $missing[] = 'Concedente'; }
    if (blank($supervisorData['name'] ?? null)) { $missing[] = 'Supervisor'; }
    if (blank($supervisorData['job_role'] ?? null)) { $missing[] = 'Cargo ou função do supervisor na concedente'; }
    if ($pendingSupervisor) {
        $qualification = $values['supervisor_proposal']['has_academic_background'] ?? null;
        if ($qualification === null || $qualification === '') {
            $missing[] = 'Qualificação do supervisor';
        } elseif ((bool) $qualification && (blank($values['supervisor_proposal']['training_course'] ?? null) || blank($values['supervisor_proposal']['education_level'] ?? null))) {
            $missing[] = 'Formação do supervisor';
        } elseif (! (bool) $qualification && blank($values['supervisor_proposal']['experience_time'] ?? null)) {
            $missing[] = 'Experiência do supervisor';
        }
    }
    if (count(array_filter($values['weekly_hours'] ?? [], fn ($hours) => $hours !== '' && $hours !== null)) !== 7) { $missing[] = 'Horas nos sete dias da semana'; }
    if (($values['legal_capacity_declaration'] ?? null) === 'minor') {
        foreach (['legal_guardian_name', 'legal_guardian_cpf', 'legal_guardian_kinship', 'legal_guardian_email'] as $field) {
            if (blank($values[$field] ?? null)) { $missing[] = 'Dados completos do responsável legal'; break; }
        }
    }
    if (($values['legal_capacity_declaration'] ?? null) === 'emancipated_minor' && $request->emancipationEvidences->isEmpty()) { $missing[] = 'Comprovante de emancipação'; }
    if ((bool) ($values['is_remunerated'] ?? false) && blank($values['grant_value'] ?? null)) { $missing[] = 'Bolsa mensal'; }
    foreach ($values['planned_pauses'] ?? [] as $pause) {
        if (blank($pause['starts_at'] ?? null) || blank($pause['ends_at'] ?? null) || blank($pause['reason'] ?? null)) { $missing[] = 'Datas e motivo de todas as pausas previstas'; break; }
    }
@endphp
@if (auth()->user()->can('update', $request) && $missing !== [])
    <flux:callout variant="warning" heading="Ainda falta preencher">
        <ul class="list-inside list-disc text-sm">@foreach ($missing as $index => $label)<li wire:key="missing-{{ $index }}">{{ $label }}</li>@endforeach</ul>
        <flux:callout.text>O envio também confere os requisitos de cada escolha e a validade dos dados.</flux:callout.text>
    </flux:callout>
@endif
<div class="space-y-6">
    <section class="space-y-3" aria-labelledby="review-student">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading id="review-student" level="3">Dados pessoais</flux:heading>
            @if (auth()->user()->can('update', $request) && in_array('personal_data', $this->editableSections, true))<flux:button size="sm" variant="outline" wire:click="goTo(0)">Editar dados pessoais</flux:button>@endif
        </div>
        <dl class="grid gap-5 text-base sm:grid-cols-2 2xl:grid-cols-3">
            @foreach (['Nome' => $studentData['name'] ?? null, 'CPF' => \App\Helpers\BrazilianDocumentHelper::formatCpf($studentData['cpf'] ?? null), 'E-mail' => $studentData['email'] ?? null,
                'Matrícula' => $studentData['registration_number'] ?? null, 'RG e emissor' => ($studentData['rg'] ?? '').' / '.($studentData['rg_issuer'] ?? ''),
                'Emissão do RG' => formatShort($studentData['rg_issue_date'] ?? null), 'Nascimento' => formatShort($studentData['birth_date'] ?? null), 'Telefone' => $studentData['phone'] ?? null,
                'Endereço' => $studentAddress === null ? null : $studentAddress->street.', '.$studentAddress->number.' — '.$studentAddress->neighborhood.', '.$studentAddress->city?->name.'/'.$studentAddress->city?->state->value.' · CEP '.$studentAddress->zip_code] as $label => $value)
                <div wire:key="review-student-{{ $loop->index }}"><dt class="text-sm text-zinc-500">{{ $label }}</dt><dd class="mt-1 break-words text-base font-medium">{{ filled($value) ? $value : 'Não informado' }}</dd></div>
            @endforeach
        </dl>
    </section>
    <flux:separator />
    <section class="space-y-3" aria-labelledby="review-internship">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading id="review-internship" level="3">Tipo, ano/semestre e orientador</flux:heading>
            @if (auth()->user()->can('update', $request) && in_array('internship', $this->editableSections, true))<flux:button size="sm" variant="outline" icon="pencil-square" wire:click="goTo(1)">Editar curso e estágio</flux:button>@endif
        </div>
        <dl class="grid gap-5 text-base sm:grid-cols-2 2xl:grid-cols-3">
            @foreach (['Curso' => $request->course?->name, 'Tipo de estágio' => $request->internship_type_snapshot['name'] ?? $request->internshipType?->name,
                'Ano/semestre' => $values['student_year_semester'] ?? null, 'Co-orientador' => $values['co_advisor_name'] ?? null,
                'Orientador' => array_key_exists('selectedAdvisor', $choices) ? $choices['selectedAdvisor']?->user?->name : $request->advisorAffiliation?->user?->name] as $label => $value)
                <div wire:key="review-internship-{{ $loop->index }}"><dt class="text-sm text-zinc-500">{{ $label }}</dt><dd class="mt-1 break-words text-base font-medium">{{ filled($value) ? $value : 'Não informado / não se aplica' }}</dd></div>
            @endforeach
        </dl>
    </section>
    <flux:separator />
    <section class="space-y-3" aria-labelledby="review-party">
        <div class="flex flex-wrap items-center justify-between gap-3"><flux:heading id="review-party" level="3">Concedente {{ $request->granting_party_registration_request_id !== null ? '· Cadastro solicitado' : '' }}</flux:heading>
            @if (auth()->user()->can('update', $request) && in_array('granting_party', $this->editableSections, true))<flux:button size="sm" variant="outline" icon="pencil-square" wire:click="goTo(2)">Editar concedente</flux:button>@endif
        </div>
        @php
            $reportedChanges = $values['granting_party_change_note'] ?? $request->granting_party_snapshot['reported_changes'] ?? null;
        @endphp
        @if (filled($reportedChanges))
            <flux:callout variant="warning" heading="Alterações da concedente informadas pelo discente"><flux:callout.text class="whitespace-pre-line">{{ $reportedChanges }}</flux:callout.text></flux:callout>
        @endif
        <dl class="grid gap-5 text-base sm:grid-cols-2 2xl:grid-cols-3">
            @foreach (['Nome' => $partyData['name'] ?? null, 'CPF/CNPJ' => \App\Helpers\BrazilianDocumentHelper::formatCpfCnpj($partyData['document_number'] ?? null),
                'Representante' => $partyData['representative_name'] ?? null, 'Cargo do representante' => $partyData['representative_role'] ?? null, 'Telefone' => $partyData['phone'] ?? null,
                'E-mail' => $partyData['email'] ?? null, 'Área de atuação' => $partyData['field_of_activity'] ?? null, 'Conselho profissional' => $partyData['professional_council'] ?? null,
                'Registro no conselho' => $partyData['council_registration_number'] ?? null, 'Processo de credenciamento' => $partyData['credentialing_process_number'] ?? null,
                'Endereço' => $workplaceAddress === null ? (filled($request->grantingPartyRegistrationRequest?->street) ? $request->grantingPartyRegistrationRequest->street.', '.$request->grantingPartyRegistrationRequest->number.' — ' : '').($request->grantingPartyRegistrationRequest?->city ?? '').'/'.($request->grantingPartyRegistrationRequest?->uf?->value ?? '') : $workplaceAddress->street.', '.$workplaceAddress->number.' — '.$workplaceAddress->neighborhood.', '.$workplaceAddress->city->name.'/'.$workplaceAddress->city->state->value.' · CEP '.$workplaceAddress->zip_code] as $label => $value)
                <div wire:key="review-party-{{ $loop->index }}"><dt class="text-sm text-zinc-500">{{ $label }}</dt><dd class="mt-1 break-words text-base font-medium">{{ filled($value) ? $value : 'Não informado / não se aplica' }}</dd></div>
            @endforeach
        </dl>
    </section>
    <flux:separator />
    <section class="space-y-3" aria-labelledby="review-supervisor">
        <div class="flex flex-wrap items-center justify-between gap-3"><flux:heading id="review-supervisor" level="3">Supervisor {{ $request->supervisor_registration_request_id !== null ? '· Cadastro solicitado' : '' }}</flux:heading>
            @if (auth()->user()->can('update', $request) && in_array('supervisor', $this->editableSections, true))<flux:button size="sm" variant="outline" icon="pencil-square" wire:click="goTo(3)">Editar supervisor</flux:button>@endif
        </div>
        <dl class="grid gap-5 text-base sm:grid-cols-2 2xl:grid-cols-3">
            @php
                $supervisorFields = ['Nome' => $supervisorData['name'] ?? null, 'Telefone' => $supervisorData['phone'] ?? null,
                    'E-mail' => $supervisorData['email'] ?? null, 'Cargo/função' => $supervisorData['job_role'] ?? null];
                if ($pendingSupervisor || isset($supervisorData['has_academic_background'])) {
                    $supervisorFields += (bool) ($supervisorData['has_academic_background'] ?? false)
                        ? ['Formação' => $supervisorData['training_course'] ?? null, 'Nível' => \App\Enums\EducationLevel::tryFrom($supervisorData['education_level'] ?? '')?->label()]
                        : ['Área de experiência' => $supervisorData['experience_area'] ?? null, 'Tempo de experiência' => $supervisorData['experience_time'] ?? null];
                }
            @endphp
            @foreach ($supervisorFields as $label => $value)
                <div wire:key="review-supervisor-{{ $loop->index }}"><dt class="text-sm text-zinc-500">{{ $label }}</dt><dd class="mt-1 break-words text-base font-medium">{{ filled($value) ? $value : 'Não informado / não se aplica' }}</dd></div>
            @endforeach
        </dl>
    </section>
    <flux:separator />
    <section class="space-y-3" aria-labelledby="review-activities">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading id="review-activities" level="3">Atividades e setor</flux:heading>
            @if (auth()->user()->can('update', $request) && in_array('activities', $this->editableSections, true))<flux:button size="sm" variant="outline" icon="pencil-square" wire:click="goTo(4)">Editar atividades</flux:button>@endif
        </div>
        <dl class="grid gap-5 text-base sm:grid-cols-2 2xl:grid-cols-3">
            @foreach (['Setor/área' => $values['internship_sector'] ?? null, 'Atividades' => $values['activities'] ?? null, 'Observações' => $values['observations'] ?? null] as $label => $value)
                <div wire:key="review-activities-{{ $loop->index }}"><dt class="text-sm text-zinc-500">{{ $label }}</dt><dd class="mt-1 whitespace-pre-line break-words text-base font-medium">{{ filled($value) ? $value : 'Não informado / não se aplica' }}</dd></div>
            @endforeach
        </dl>
    </section>
    <flux:separator />
    <section class="space-y-3" aria-labelledby="review-schedule">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading id="review-schedule" level="3">Jornada e pausas previstas</flux:heading>
            @if (auth()->user()->can('update', $request) && in_array('schedule', $this->editableSections, true))<flux:button size="sm" variant="outline" icon="pencil-square" wire:click="goTo(6)">Editar jornada e pausas</flux:button>@endif
        </div>
        <dl class="grid gap-5 text-base sm:grid-cols-2 2xl:grid-cols-3">
            <div><dt class="text-sm text-zinc-500">Previsão de término</dt><dd class="mt-1 break-words text-base font-medium">{{ $request->projected_end_date === null ? 'Calculada no envio, após conferir a jornada e o calendário do local' : formatShort($request->projected_end_date) }}</dd></div>
        </dl>
        <dl class="grid grid-cols-2 gap-3 text-base sm:grid-cols-4">
            @foreach (['sunday' => 'Domingo', 'monday' => 'Segunda-feira', 'tuesday' => 'Terça-feira', 'wednesday' => 'Quarta-feira', 'thursday' => 'Quinta-feira', 'friday' => 'Sexta-feira', 'saturday' => 'Sábado'] as $key => $label)
                <div wire:key="review-hours-{{ $key }}"><dt class="text-sm text-zinc-500">{{ $label }}</dt><dd class="mt-1 font-medium">{{ ($values['weekly_hours'][$key] ?? '') === '' ? 'Não informado' : $values['weekly_hours'][$key].' h' }}</dd></div>
            @endforeach
        </dl>
        <div class="space-y-3">
            <flux:heading level="4">Pausas previstas</flux:heading>
            @forelse ($values['planned_pauses'] ?? [] as $key => $pause)
                <div wire:key="review-pause-{{ $key }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700"><flux:text class="font-medium">{{ formatShort($pause['starts_at'] ?? null) }} a {{ formatShort($pause['ends_at'] ?? null) }}</flux:text><flux:text size="sm" class="mt-1 whitespace-pre-line">{{ $pause['reason'] ?? 'Motivo não informado' }}</flux:text></div>
            @empty
                <flux:text size="sm">Nenhuma pausa prevista informada.</flux:text>
            @endforelse
        </div>
    </section>
    <flux:separator />
    <section class="space-y-3" aria-labelledby="review-start-date">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading id="review-start-date" level="3">Data de início</flux:heading>
            @if (auth()->user()->can('update', $request) && in_array('planned_start_date', $this->editableSections, true))<flux:button size="sm" variant="outline" icon="pencil-square" wire:click="goTo(6)">Editar data de início</flux:button>@endif
        </div>
        <dl class="grid gap-5 text-base sm:grid-cols-2 2xl:grid-cols-3">
            <div><dt class="text-sm text-zinc-500">Início proposto</dt><dd class="mt-1 font-medium">{{ formatShort($values['planned_start_date'] ?? null) }}</dd></div>
        </dl>
    </section>
    <flux:separator />
    <section class="space-y-3" aria-labelledby="review-legal-capacity">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading id="review-legal-capacity" level="3">Capacidade civil e comprovante</flux:heading>
            @if (auth()->user()->can('update', $request) && in_array('legal_capacity', $this->editableSections, true))<flux:button size="sm" variant="outline" icon="pencil-square" wire:click="goTo(5)">Editar capacidade civil</flux:button>@endif
        </div>
        <dl class="grid gap-5 text-base sm:grid-cols-2 2xl:grid-cols-3">
            <div><dt class="text-sm text-zinc-500">Capacidade civil</dt><dd class="mt-1 font-medium">{{ \App\Enums\LegalCapacityDeclaration::tryFrom($values['legal_capacity_declaration'] ?? '')?->label() ?? 'Não informado / não se aplica' }}</dd></div>
        </dl>
        @if (($values['legal_capacity_declaration'] ?? null) === 'minor')
            <flux:callout heading="Responsável legal"><flux:callout.text>{{ $values['legal_guardian_name'] ?? 'Não informado' }} · {{ $values['legal_guardian_kinship'] ?? '' }} · {{ \App\Helpers\BrazilianDocumentHelper::formatCpf($values['legal_guardian_cpf'] ?? null) }} · {{ $values['legal_guardian_email'] ?? '' }}</flux:callout.text></flux:callout>
        @elseif (($values['legal_capacity_declaration'] ?? null) === 'emancipated_minor' && $request->emancipationEvidences->isNotEmpty())
            <div class="flex flex-wrap items-center gap-3"><flux:text>Comprovante de emancipação: {{ $request->emancipationEvidences->first()->status->label() }}</flux:text><flux:button size="sm" variant="outline" icon="document" :href="route('internship-requests.evidence', $request->emancipationEvidences->first())" target="_blank" rel="noopener">Ver comprovante</flux:button></div>
        @endif
    </section>
    <flux:separator />
    <section class="space-y-3" aria-labelledby="review-remuneration">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading id="review-remuneration" level="3">Remuneração</flux:heading>
            @if (auth()->user()->can('update', $request) && in_array('remuneration', $this->editableSections, true))<flux:button size="sm" variant="outline" icon="pencil-square" wire:click="goTo(7)">Editar remuneração</flux:button>@endif
        </div>
        <dl class="grid gap-5 text-base sm:grid-cols-2 2xl:grid-cols-3">
            @foreach (['Remuneração' => ($values['is_remunerated'] ?? null) === null || ($values['is_remunerated'] ?? '') === '' ? null : ((bool) $values['is_remunerated'] ? 'Remunerado' : 'Não remunerado'),
                'Bolsa mensal' => (bool) ($values['is_remunerated'] ?? false) ? formatCurrency(isset($values['grant_value']) ? (float) $values['grant_value'] : null) : null,
                'Auxílio-transporte' => (bool) ($values['is_remunerated'] ?? false) ? formatCurrency(isset($values['transportation_allowance']) ? (float) $values['transportation_allowance'] : null) : null] as $label => $value)
                <div wire:key="review-remuneration-{{ $loop->index }}"><dt class="text-sm text-zinc-500">{{ $label }}</dt><dd class="mt-1 break-words text-base font-medium">{{ filled($value) ? $value : 'Não informado / não se aplica' }}</dd></div>
            @endforeach
        </dl>
    </section>
</div>
@if ($sent)<flux:text size="sm">Ciência registrada no último envio em {{ formatDateTime($request->terms_accepted_at) }}. Os dados históricos não acompanham alterações posteriores do perfil.</flux:text>@endif
@can('submit', $request)
    <div class="space-y-4 rounded-xl bg-zinc-50 p-5 dark:bg-zinc-900">
        <flux:field variant="inline">
            <flux:checkbox wire:model.live="termsAccepted" />
            <flux:label>Li e concordo com as normas para o estágio. Conferi os dados e estou ciente de que o envio depende de análise e não autoriza o início das atividades.</flux:label>
            <flux:error name="terms_accepted" />
        </flux:field>
        @if ($missing !== [])<flux:text size="sm">Complete as informações indicadas para habilitar o envio. Você pode salvar o rascunho e continuar depois.</flux:text>@endif
        <flux:button variant="primary" wire:click="submit" :disabled="$missing !== [] || ! $termsAccepted" wire:loading.attr="disabled" wire:target="submit">{{ $request->status->value === 'pending_correction' ? 'Reenviar para análise' : 'Enviar solicitação para análise' }}</flux:button>
    </div>
@endcan
