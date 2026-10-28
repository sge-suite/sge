@if ($step === 0)
    <flux:text>Confira e complete seus dados aqui. Ao salvar, eles também serão atualizados em “Dados pessoais” nas configurações. Use “Salvar e continuar depois” se ainda faltar alguma informação.</flux:text>
    <div class="grid items-start gap-5 sm:grid-cols-2">
        <div class="sm:col-span-2"><flux:input :value="$personal['user']->name" label="Nome completo" error:name="student_name" readonly /></div>
        <flux:input :value="\App\Helpers\BrazilianDocumentHelper::formatCpf($personal['user']->cpf)" label="CPF" error:name="student_cpf" readonly />
        <flux:input :value="$personal['owner']->email" label="E-mail do vínculo" error:name="student_email" readonly />
        <flux:input :value="$personal['owner']->registration_number" label="Matrícula" error:name="student_registration" readonly />
    </div>
    <flux:text size="sm">O estágio usa o e-mail deste vínculo. Para corrigir nome, CPF, e-mail ou matrícula, procure o Setor de Estágio.</flux:text>
    <flux:separator />
    <div class="grid items-start gap-5 sm:grid-cols-2 xl:grid-cols-3">
        <flux:input wire:model="values.personal_data.rg" label="RG" error:name="personal_data.rg" maxlength="255" required />
        <flux:input wire:model="values.personal_data.rg_issuer" label="Órgão emissor do RG" placeholder="SSP/RS" error:name="personal_data.rg_issuer" maxlength="255" required />
        <flux:input wire:model="values.personal_data.rg_issue_date" label="Data de emissão do RG" error:name="personal_data.rg_issue_date" type="date" :max="now()->toDateString()" required />
        <flux:input wire:model.blur="values.personal_data.birth_date" label="Data de nascimento" error:name="personal_data.birth_date" type="date" :max="now()->toDateString()" autocomplete="bday" required />
        <flux:input wire:model="values.personal_data.phone" label="Telefone com DDD" error:name="personal_data.phone" type="tel" autocomplete="tel" placeholder="(00) 00000-0000" maxlength="20" required />
    </div>
    <flux:separator />
    <section class="space-y-4" aria-labelledby="personal-address-heading">
        <flux:heading id="personal-address-heading" level="3">Endereço atual</flux:heading>
        @if ($errors->has('personal_data.address'))<flux:error name="personal_data.address" />@endif
        <x-address.form-fields :values="$values['personal_data']['address'] ?? []" model="values.personal_data.address" error-prefix="personal_data.address" name-prefix="personal_data.address" city-model="values.personal_data.address.city_id" :selected-city-id="$values['personal_data']['address']['city_id'] ?? null" :selection-key="'internship-personal-city-'.$citySelectionVersion" :personal-data="true" :city-message="$cityLookupMessage" :cep-lookup-changes="$this->cepLookupPreviewChanges()" />
    </section>
    <flux:text size="sm">Todos os dados desta etapa são obrigatórios para continuar. Se faltar alguma informação, use “Salvar e continuar depois”.</flux:text>
@elseif ($step === 1)
    <flux:text>O curso e o campus vêm do vínculo com que você está usando o SGE.</flux:text>
    <flux:callout><flux:callout.text>{{ $personal['owner']->course->name }} · {{ $personal['owner']->campus->name }}</flux:callout.text></flux:callout>
    @if ($choices['types']->count() > 1)
        <x-select wire:model.live="values.internship_type_id" :value="$values['internship_type_id'] ?? ''" label="Tipo de estágio" placeholder="Selecione o tipo" :options="$choices['types']->mapWithKeys(fn ($type) => [$type->id => $type->name])->all()" :invalid="$errors->has('internship_type_id')" :error="$errors->first('internship_type_id')" required />
    @endif
    @php
        $selectedType = $choices['types']->firstWhere('id', (int) ($values['internship_type_id'] ?? 0));
    @endphp
    @if ($selectedType)
        <flux:callout :heading="$selectedType->name">
            <flux:callout.text>{{ $selectedType->required_hours }} horas exigidas · até {{ $selectedType->max_daily_hours }} horas por dia e {{ $selectedType->max_weekly_hours }} horas por semana.</flux:callout.text>
        </flux:callout>
    @endif
    @if ($choices['types']->count() <= 1)<flux:error name="internship_type_id" />@endif
    @if ($choices['types']->isEmpty())<flux:callout variant="warning">Seu curso ainda não possui um tipo ativo. Salve o rascunho e procure o Setor de Estágio.</flux:callout>@endif
    <x-select wire:model="values.student_year_semester" :value="$values['student_year_semester'] ?? ''" label="Ano ou semestre em que você está matriculado" placeholder="Selecione seu ano ou semestre" :options="$selectedType?->academicPeriodOptions() ?? []" :disabled="$selectedType === null || empty($selectedType->allowed_academic_periods)" :error="$errors->first('student_year_semester')" required />
    @if ($selectedType && empty($selectedType->allowed_academic_periods))
        <flux:callout variant="warning">O Setor de Estágio precisa configurar os anos ou semestres permitidos para este tipo. Salve o rascunho e entre em contato com o Setor.</flux:callout>
    @endif
    <x-select search-source="advisors" wire:model="values.advisor_affiliation_id" :value="$values['advisor_affiliation_id'] ?? ''" label="Orientador" placeholder="Selecione o orientador" :options="$choices['advisors']->mapWithKeys(fn ($advisor) => [$advisor->id => $advisor->user->name.' - '.$advisor->email])->all()" :error="$errors->first('advisor_affiliation_id')" searchable required />
    <flux:text size="sm">Selecione o orientador com quem combinou o estágio. O Setor conferirá a escolha durante a análise.</flux:text>
    <flux:input wire:model="values.co_advisor_name" error:name="co_advisor_name" label="Co-orientador (opcional)" maxlength="255" />
    @if ($choices['advisors']->isEmpty())<flux:callout variant="warning">Não há orientadores ativos cadastrados no seu campus. Salve o rascunho e procure o Setor de Estágio.</flux:callout>@endif
@elseif ($step === 2)
    <flux:text>A concedente é a empresa ou pessoa onde você fará o estágio. Busque pelo nome ou CPF/CNPJ. Você não precisa saber se ela já está cadastrada.</flux:text>
    <flux:error name="granting_party_mode" />
    @if ($partyMatchMessage)<div role="status" tabindex="-1" x-data x-init="$el.focus({ preventScroll: true })"><flux:callout :variant="$choices['selectedParty'] ? 'success' : 'warning'" icon="information-circle" :heading="$partyMatchMessage" /></div>@endif
    @if (! $pendingParty)
        <x-select wire:model.live="values.granting_party_id" :value="$values['granting_party_id'] ?? ''" label="Onde você fará o estágio?" placeholder="Buscar por nome ou CPF/CNPJ"
            :options="collect([$choices['selectedParty']])->merge($choices['parties'])->filter()->unique('id')->mapWithKeys(fn ($party) => [$party->id => $party->selectionLabel()])->all()"
            :empty="mb_strlen(trim($partySearch)) < 2 && blank($partyCityId) ? 'Digite pelo menos dois caracteres para buscar.' : 'Não encontramos uma concedente disponível para seu campus com esses filtros.'"
            :invalid="$errors->has('granting_party_id')" :error="$errors->first('granting_party_id')" required>
            <x-slot:search>
                <flux:input wire:model.live.debounce.400ms="partySearch" wire:ignore.self placeholder="Nome ou CPF/CNPJ…" aria-label="Buscar concedente por nome ou CPF/CNPJ" icon="magnifying-glass" size="sm" maxlength="100"
                    role="combobox" aria-autocomplete="list" x-bind:aria-expanded="open" x-bind:aria-controls="listElement()?.id" x-bind:aria-activedescendant="activeId()" />
                <span wire:loading wire:target="partySearch,partyCityId" class="block px-2 py-1 text-xs text-zinc-500" role="status">Buscando concedentes…</span>
            </x-slot:search>
        </x-select>
        @if (! $choices['selectedParty'])
        <x-accordion title="Filtrar pela cidade (opcional)" :open="filled($partyCityId)">
            <div class="space-y-3">
                <livewire:cities.select wire:model.live="partyCityId" :selected-city-id="$partyCityId" :city-error="''" :personal-data="true" field-name="party_city_id" :required="false" :key="'existing-party-city-'.$requestId.'-'.$partyCityId" />
                @if (filled($partyCityId))<flux:button size="sm" variant="ghost" wire:click="clearPartyCityFilter" wire:loading.attr="disabled">Limpar filtro de cidade</flux:button>@endif
            </div>
        </x-accordion>
        @if ($choices['parties']->count() === 20)<flux:text size="sm">Exibimos até 20 resultados. Refine a busca pelo nome, documento ou cidade para encontrar a unidade correta.</flux:text>@endif
        @if ($partyMatchMessage)
            <flux:text size="sm">Se a unidade correta não estiver na lista, procure o Setor de Estágio para conferir os cadastros desse documento.</flux:text>
        @else
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text size="sm">Não encontrou a empresa, pessoa ou unidade onde fará o estágio?</flux:text>
            <flux:button variant="outline" wire:click="completeRegistration('granting_party')" wire:loading.attr="disabled">Não encontrei a concedente</flux:button>
        </div>
        @endif
        @endif
        @if ($choices['selectedParty'])
            @php
        $party = $choices['selectedParty'];
    @endphp
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading level="3">Confira a concedente selecionada</flux:heading>
                <flux:button size="sm" variant="ghost" wire:click="changeRegistration('granting_party')" wire:loading.attr="disabled">Trocar concedente</flux:button>
            </div>
            @include('pages.internship-requests.partials.granting-party-details')
            <flux:textarea wire:model="values.granting_party_change_note" label="Algum dado da concedente mudou?" description="Informe o campo e o valor correto, por exemplo representante, cargo, CPF/CNPJ, endereço ou contato. O Setor conferirá as alterações; o cadastro não será alterado automaticamente." rows="3" maxlength="5000" error:name="granting_party_change_note" />
        @endif
    @else
        @php
        $companyProposal = ($values['granting_party_proposal']['document_type'] ?? '') === 'cnpj';
    @endphp
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading level="3">Informe os dados da concedente</flux:heading>
            <flux:button size="sm" variant="ghost" wire:click="searchRegistration('granting_party')" wire:loading.attr="disabled">Voltar para busca</flux:button>
        </div>
        <flux:callout icon="information-circle" heading="Comece pelo CPF ou CNPJ">
            <flux:callout.text>Se encontrarmos um cadastro disponível para seu campus, vamos selecioná-lo para você conferir. Caso contrário, complete os dados e continue: o Setor analisará o cadastro junto com sua solicitação de estágio.</flux:callout.text>
        </flux:callout>
        <div class="grid items-start gap-5 sm:grid-cols-2">
            <x-select wire:model.live="values.granting_party_proposal.document_type" :value="$values['granting_party_proposal']['document_type'] ?? ''" label="Tipo de documento" placeholder="Selecione" :options="['cpf' => 'Pessoa física — CPF', 'cnpj' => 'Pessoa jurídica — CNPJ']" :invalid="$errors->has('granting_party_proposal.document_type')" :error="$errors->first('granting_party_proposal.document_type')" required />
            @if (filled($values['granting_party_proposal']['document_type'] ?? null))
            <div wire:key="proposal-document-{{ $values['granting_party_proposal']['document_type'] }}">
                <flux:input wire:model.blur="values.granting_party_proposal.document_number" :label="$companyProposal ? 'CNPJ' : 'CPF'" error:name="granting_party_proposal.document_number" :maxlength="$companyProposal ? 18 : 14" :placeholder="$companyProposal ? '00.000.000/0000-00' : '000.000.000-00'" required />
                <span wire:loading wire:target="values.granting_party_proposal.document_number" role="status" class="text-sm text-zinc-500">Conferindo cadastro…</span>
            </div>
            <div class="sm:col-span-2">
                <flux:input wire:model="values.granting_party_proposal.name" :label="$companyProposal ? 'Razão social' : 'Nome completo'" error:name="granting_party_proposal.name" maxlength="255" required />
            </div>
            @if ($companyProposal)
                <flux:input wire:model="values.granting_party_proposal.representative_name" label="Nome do representante" error:name="granting_party_proposal.representative_name" maxlength="255" required />
                <flux:input wire:model="values.granting_party_proposal.representative_role" label="Cargo do representante" error:name="granting_party_proposal.representative_role" maxlength="255" required />
            @endif
            <flux:input wire:model="values.granting_party_proposal.phone" label="Telefone com DDD" type="tel" error:name="granting_party_proposal.phone" placeholder="(00) 00000-0000" maxlength="20" required />
            <flux:input wire:model="values.granting_party_proposal.email" label="E-mail" type="email" error:name="granting_party_proposal.email" maxlength="254" required />
            @endif
        </div>
        @if (filled($values['granting_party_proposal']['document_type'] ?? null) && $companyProposal)
            <livewire:cities.select wire:model.live="values.granting_party_proposal.city_id" :selected-city-id="$values['granting_party_proposal']['city_id'] ?? null" :city-error="$errors->first('granting_party_proposal.city_id')" :personal-data="true" field-name="granting_party_proposal[city_id]" :key="'company-proposal-city-'.$citySelectionVersion" />
        @elseif (filled($values['granting_party_proposal']['document_type'] ?? null))
            <flux:heading level="3">Endereço completo</flux:heading>
            <x-address.form-fields :values="$values['granting_party_proposal']" model="values.granting_party_proposal" error-prefix="granting_party_proposal" name-prefix="granting_party_proposal" city-model="values.granting_party_proposal.city_id" :selected-city-id="$values['granting_party_proposal']['city_id'] ?? null" :selection-key="'internship-party-city-'.$citySelectionVersion" :personal-data="true" :city-message="$cityLookupMessage" :cep-lookup-changes="$this->cepLookupPreviewChanges()" />
        @endif
        <flux:text size="sm">Todos os campos exibidos são obrigatórios para enviar. Você pode salvar o preenchimento parcial como rascunho.</flux:text>
    @endif
@elseif ($step === 3)
    <flux:text>Quem acompanhará suas atividades na concedente? Busque pelo nome ou CPF do supervisor. Você não precisa saber se ele já está cadastrado.</flux:text>
    <flux:error name="supervisor_mode" />
    @if ($supervisorMatchMessage)<div role="status" tabindex="-1" x-data x-init="$el.focus({ preventScroll: true })"><flux:callout variant="success" icon="check-circle" :heading="$supervisorMatchMessage" /></div>@endif
    @if ($pendingSupervisor)
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading level="3">Informe os dados do supervisor</flux:heading>
            <flux:button size="sm" variant="ghost" wire:click="searchRegistration('supervisor')" wire:loading.attr="disabled">Voltar para busca</flux:button>
        </div>
        <flux:callout icon="information-circle" heading="Comece pelo CPF do supervisor">
            <flux:callout.text>Se este CPF tiver um vínculo ativo de supervisor no seu campus, vamos selecioná-lo para você conferir. Caso contrário, complete os dados e continue: o Setor analisará o cadastro junto com sua solicitação de estágio.</flux:callout.text>
        </flux:callout>
        <div class="grid items-start gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <flux:input wire:model.blur="values.supervisor_proposal.cpf" label="CPF do supervisor" error:name="supervisor_proposal.cpf" placeholder="000.000.000-00" maxlength="14" required />
                <span wire:loading wire:target="values.supervisor_proposal.cpf" role="status" class="text-sm text-zinc-500">Conferindo vínculo de supervisor…</span>
            </div>
            <div class="sm:col-span-2"><flux:input wire:model="values.supervisor_proposal.name" label="Nome completo do supervisor" error:name="supervisor_proposal.name" maxlength="255" required /></div>
            <flux:input wire:model="values.supervisor_proposal.phone" label="Telefone do supervisor com DDD" type="tel" error:name="supervisor_proposal.phone" placeholder="(00) 00000-0000" maxlength="20" required />
            <div class="sm:col-span-2"><flux:input wire:model="values.supervisor_proposal.email" label="E-mail do supervisor" type="email" error:name="supervisor_proposal.email" maxlength="255" required /></div>
        </div>
    @else
        <x-select wire:model.live="values.supervisor_affiliation_id" :value="$values['supervisor_affiliation_id'] ?? ''" label="Supervisor do seu campus" placeholder="Buscar por nome ou CPF" :empty="mb_strlen(trim($supervisorSearch)) < 2 ? 'Digite pelo menos dois caracteres para buscar.' : 'Não encontramos um vínculo de supervisor disponível para seu campus com esses dados.'" :options="collect([$choices['selectedSupervisor']])->merge($choices['supervisors'])->filter()->unique('id')->mapWithKeys(fn ($supervisor) => [$supervisor->id => $supervisor->user->name.' · '.$supervisor->email])->all()" :invalid="$errors->has('supervisor_affiliation_id')" :error="$errors->first('supervisor_affiliation_id')" required>
            <x-slot:search>
                <flux:input wire:model.live.debounce.400ms="supervisorSearch" wire:ignore.self placeholder="Nome ou CPF…" aria-label="Buscar supervisor por nome ou CPF" icon="magnifying-glass" size="sm" maxlength="100"
                    role="combobox" aria-autocomplete="list" x-bind:aria-expanded="open" x-bind:aria-controls="listElement()?.id" x-bind:aria-activedescendant="activeId()" />
                <span wire:loading wire:target="supervisorSearch" class="block px-2 py-1 text-xs text-zinc-500" role="status">Buscando supervisores…</span>
            </x-slot:search>
        </x-select>
        @if (! $choices['selectedSupervisor'])
        @if ($choices['supervisors']->count() === 20)<flux:text size="sm">Exibimos até 20 resultados. Refine a busca pelo nome ou CPF.</flux:text>@endif
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text size="sm">Não encontrou quem acompanhará suas atividades?</flux:text>
            <flux:button variant="outline" wire:click="completeRegistration('supervisor')" wire:loading.attr="disabled">Não encontrei o supervisor</flux:button>
        </div>
        @endif
        @if ($choices['selectedSupervisor'])
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading level="3">Confira o supervisor selecionado</flux:heading>
                <flux:button size="sm" variant="ghost" wire:click="changeRegistration('supervisor')" wire:loading.attr="disabled">Trocar supervisor</flux:button>
            </div>
            <flux:callout heading="{{ $choices['selectedSupervisor']->user->name }}"><flux:callout.text>{{ $choices['selectedSupervisor']->email }} · {{ filled($choices['selectedSupervisor']->user->personalData?->phone) ? \App\Helpers\BrazilianContactHelper::formatPhone($choices['selectedSupervisor']->user->personalData->phone) : 'Telefone não informado no cadastro' }}</flux:callout.text></flux:callout>
        @endif
    @endif
    @if ($pendingSupervisor)
        <x-select wire:model.live="values.supervisor_proposal.has_academic_background" :value="$values['supervisor_proposal']['has_academic_background'] ?? ''" label="Qualificação relacionada às atividades" placeholder="Selecione" :options="['1' => 'Formação na área', '0' => 'Experiência profissional na área']" :invalid="$errors->has('supervisor_proposal.has_academic_background')" :error="$errors->first('supervisor_proposal.has_academic_background')" required />
        @if (($values['supervisor_proposal']['has_academic_background'] ?? '') !== '' && ($values['supervisor_proposal']['has_academic_background'] ?? null) !== null)
            @if ((bool) $values['supervisor_proposal']['has_academic_background'])
                <div class="grid items-start gap-5 sm:grid-cols-2">
                    <flux:input wire:model="values.supervisor_proposal.training_course" label="Curso de formação" error:name="supervisor_proposal.training_course" placeholder="Ex.: Técnico em Administração" maxlength="255" required />
                    <x-select wire:model.live="values.supervisor_proposal.education_level" :value="$values['supervisor_proposal']['education_level'] ?? ''" label="Nível de formação" placeholder="Selecione" :options="\App\Enums\EducationLevel::options()" :invalid="$errors->has('supervisor_proposal.education_level')" :error="$errors->first('supervisor_proposal.education_level')" required />
                </div>
            @else
                <flux:textarea wire:model="values.supervisor_proposal.experience_time" label="Tempo de experiência na função" error:name="supervisor_proposal.experience_time" placeholder="Ex.: 3 anos em controle de qualidade" maxlength="255" rows="3" required />
            @endif
        @endif
    @endif
    <flux:separator />
    <flux:text>Informe o cargo ou função exercido pelo supervisor na concedente.</flux:text>
    <flux:input wire:model="values.supervisor_professional.job_role" label="Cargo ou função na concedente" error:name="supervisor_professional.job_role" maxlength="255" required />
@elseif ($step === 4)
    <flux:text>Descreva as atividades combinadas com o supervisor e o orientador. Use verbos de ação e dê detalhes suficientes para o Setor avaliar a proposta.</flux:text>
    <flux:input wire:model="values.internship_sector" label="Setor ou área onde fará o estágio" error:name="internship_sector" placeholder="Ex.: Produção, lavoura ou departamento de compras" maxlength="255" required />
    <flux:textarea wire:model="values.activities" label="Atividades previstas" error:name="activities" placeholder="Descreva o que você fará no estágio, conforme combinado com o supervisor." rows="6" maxlength="20000" required />
    <flux:textarea wire:model="values.observations" label="Observações (opcional)" error:name="observations" rows="3" maxlength="10000" />
@elseif ($step === 5)
    @if ($this->isAdult === null)
        <flux:callout variant="warning">Preencha uma data de nascimento válida na etapa “Seus dados” para definir sua capacidade civil.</flux:callout>
    @elseif ($this->isAdult)
        <flux:callout heading="Maior de idade" icon="check-circle">
            <flux:callout.text>Você já completou 18 anos, conforme a data de nascimento informada ({{ formatShort($values['personal_data']['birth_date']) }}). Sua capacidade civil foi definida automaticamente.</flux:callout.text>
        </flux:callout>
    @else
        <flux:text>Conforme sua data de nascimento, você ainda não completou 18 anos. Selecione sua situação.</flux:text>
        <flux:radio.group wire:model.live="values.legal_capacity_declaration" label="Sua situação" error:name="legal_capacity_declaration" label:required variant="cards" class="grid gap-3 sm:grid-cols-2" required>
            <flux:radio value="minor" label="Menor de idade com responsável legal" />
            <flux:radio value="emancipated_minor" label="Menor emancipado" />
        </flux:radio.group>
    @endif
    @if ($this->isAdult !== false)<flux:error name="legal_capacity_declaration" />@endif
    @if ($this->isAdult === false && ($values['legal_capacity_declaration'] ?? '') === 'minor')
        <flux:text size="sm">Informe o mesmo responsável legal registrado em sua matrícula.</flux:text>
        <div class="grid items-start gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2"><flux:input wire:model="values.legal_guardian_name" label="Nome completo do responsável legal" error:name="legal_guardian_name" maxlength="255" required /></div>
            <flux:input wire:model="values.legal_guardian_cpf" label="CPF do responsável legal" error:name="legal_guardian_cpf" maxlength="14" placeholder="000.000.000-00" required />
            <flux:input wire:model="values.legal_guardian_kinship" label="Grau de parentesco" error:name="legal_guardian_kinship" maxlength="80" placeholder="Ex.: Mãe, pai ou outro responsável" required />
            <div class="sm:col-span-2"><flux:input wire:model="values.legal_guardian_email" label="E-mail do responsável legal" type="email" error:name="legal_guardian_email" maxlength="255" required /></div>
        </div>
    @elseif ($this->isAdult === false && ($values['legal_capacity_declaration'] ?? '') === 'emancipated_minor')
        <flux:text>Envie o comprovante pelo SGE. Somente você e o Setor autorizado poderão acessá-lo. O envio aguarda análise e não comprova aprovação.</flux:text>
        @can('upload', [\App\Models\EmancipationEvidence::class, $request])
            <x-file-upload wire:model="evidence" :files="$evidence" label="Comprovante de emancipação" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" description="PDF, JPG ou PNG, até 10 MB. O comprovante será anexado ao salvar esta etapa." :required="$request->emancipationEvidences->isEmpty() || in_array($request->emancipationEvidences->first()?->status, [\App\Enums\EmancipationEvidenceStatus::Returned, \App\Enums\EmancipationEvidenceStatus::Cancelled], true)" preview />
            <flux:text size="sm">Um novo comprovante preserva os envios anteriores. Confira o status de cada arquivo abaixo.</flux:text>
        @endcan
        @foreach ($request->emancipationEvidences as $item)
            <div wire:key="evidence-{{ $item->id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <div class="min-w-0 space-y-2"><flux:badge>{{ $item->status->label() }}</flux:badge><flux:text size="sm">Envio de {{ formatDateTime($item->created_at) }}</flux:text>@if($item->return_reason)<flux:text class="text-red-600! dark:text-red-400!">{{ $item->return_reason }}</flux:text>@endif</div>
                <flux:button variant="outline" icon="document" :href="route('internship-requests.evidence', $item)" target="_blank" rel="noopener">Ver comprovante</flux:button>
            </div>
        @endforeach
    @endif
@elseif ($step === 6)
    <flux:text>Informe as horas de atividade em cada dia da semana, descontando intervalos de descanso e alimentação. Use zero nos dias sem estágio.</flux:text>
    @php
        $type = $choices['types']->firstWhere('id', (int) ($values['internship_type_id'] ?? 0));
    @endphp
    @if ($type)<flux:callout><flux:callout.text>{{ $type->required_hours }} horas exigidas · até {{ $type->max_daily_hours }} horas por dia e {{ $type->max_weekly_hours }} por semana.</flux:callout.text></flux:callout>@endif
    @if (in_array('schedule', $this->editableSections, true))
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading level="3">Jornada semanal</flux:heading>
            <flux:button type="button" size="sm" variant="outline" wire:click="applyWeekdayHours" :disabled="blank($values['weekly_hours']['monday'] ?? null)" wire:loading.attr="disabled">Repetir horas de segunda a sexta</flux:button>
        </div>
        <flux:text size="sm">Informe horas inteiras. O atalho repete as horas de segunda-feira nos cinco dias úteis e preenche sábado e domingo com zero.</flux:text>
        <div class="grid items-start gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach (['monday' => 'Segunda-feira', 'tuesday' => 'Terça-feira', 'wednesday' => 'Quarta-feira', 'thursday' => 'Quinta-feira', 'friday' => 'Sexta-feira', 'saturday' => 'Sábado', 'sunday' => 'Domingo'] as $day => $label)
                <flux:input wire:model.live.debounce.400ms="values.weekly_hours.{{ $day }}" type="number" min="0" :max="$type?->max_daily_hours ?? 24" step="1" :label="$label.' (horas)'" placeholder="0" error:name="weekly_hours.{{ $day }}" wire:key="hours-{{ $day }}" required />
            @endforeach
        </div>
        @php
            $weeklyTotal = array_sum(array_filter($values['weekly_hours'] ?? [], fn ($hours) => is_numeric($hours)));
        @endphp
        <flux:badge :color="$type && $weeklyTotal > $type->max_weekly_hours ? 'red' : 'zinc'">Total informado: {{ $weeklyTotal }} h por semana</flux:badge>
        @if ($errors->has('weekly_hours'))<flux:error name="weekly_hours" />@endif
    @endif
    @if (in_array('planned_start_date', $this->editableSections, true))
        <flux:separator />
        <flux:input wire:model.live="values.planned_start_date" type="date" label="Data proposta para iniciar" :min="now(config('app.timezone'))->addDays(7)->toDateString()" error:name="planned_start_date" required />
        <flux:text size="sm">Escolha um dia com horas na jornada, sem feriado ou pausa, com pelo menos sete dias corridos após o envio. O início depende da liberação pelo Setor após a documentação.</flux:text>
    @endif
    @if (in_array('schedule', $this->editableSections, true))
        <flux:separator />
        <section class="space-y-4" aria-labelledby="planned-pauses-heading">
            <div><flux:heading level="3" id="planned-pauses-heading">Pausas previstas (opcional)</flux:heading><flux:text class="mt-2">Já sabe de algum período sem atividades, como férias ou recesso da concedente? Adicione as datas e o motivo. Todos os dias do período, incluindo o início e o fim, ficarão fora da contagem de horas.</flux:text></div>
            @if (empty($values['planned_pauses']))<flux:text size="sm">Nenhuma pausa prevista. Se não houver interrupções combinadas, continue sem adicionar um período.</flux:text>@endif
            @foreach ($values['planned_pauses'] ?? [] as $key => $pause)
                <div wire:key="planned-pause-{{ $key }}" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex items-center justify-between gap-3"><flux:heading level="4">Pausa {{ $loop->iteration }}</flux:heading><flux:button type="button" variant="ghost" size="sm" icon="x-mark" wire:click="removePlannedPause('{{ $key }}')" :aria-label="'Remover pausa '.$loop->iteration">Remover</flux:button></div>
                    <div class="grid items-start gap-5 sm:grid-cols-2">
                        <flux:input wire:model.live="values.planned_pauses.{{ $key }}.starts_at" type="date" label="Início da pausa" :min="$values['planned_start_date'] ?: null" error:name="planned_pauses.{{ $key }}.starts_at" required />
                        <flux:input wire:model="values.planned_pauses.{{ $key }}.ends_at" type="date" label="Fim da pausa" :min="$pause['starts_at'] ?: ($values['planned_start_date'] ?: null)" error:name="planned_pauses.{{ $key }}.ends_at" required />
                        <div class="sm:col-span-2"><flux:textarea wire:model="values.planned_pauses.{{ $key }}.reason" label="Motivo da pausa" placeholder="Ex.: Recesso da concedente" maxlength="1000" rows="2" error:name="planned_pauses.{{ $key }}.reason" required /></div>
                    </div>
                </div>
            @endforeach
            <flux:error name="planned_pauses" />
            <flux:button type="button" variant="outline" icon="plus" wire:click="addPlannedPause" :disabled="count($values['planned_pauses'] ?? []) >= \App\Support\InternshipPlannedPauses::MAX_PERIODS" wire:loading.attr="disabled">Adicionar pausa</flux:button>
        </section>
    @endif
    <flux:callout><flux:callout.text>A previsão de término considera a carga exigida, a jornada, os feriados do local, as pausas previstas e a margem do tipo de estágio. O Setor confere esses dados antes do aceite.</flux:callout.text></flux:callout>
@elseif ($step === 7)
    <flux:radio.group wire:model.live="values.is_remunerated" label="O estágio será remunerado?" error:name="is_remunerated" label:required variant="cards" class="grid gap-3 sm:grid-cols-2" required><flux:radio value="1" label="Sim" description="Informe a bolsa e o auxílio-transporte, se houver." /><flux:radio value="0" label="Não" description="Estágio sem remuneração." /></flux:radio.group>
    @if (($values['is_remunerated'] ?? '') !== '' && ($values['is_remunerated'] ?? null) !== null && (bool) $values['is_remunerated'])
        <div class="grid items-start gap-5 sm:grid-cols-2"><flux:input wire:model="values.grant_value" type="text" inputmode="decimal" data-decimal-input label="Bolsa mensal (R$)" error:name="grant_value" placeholder="0,00" required />
        <flux:input wire:model="values.transportation_allowance" type="text" inputmode="decimal" data-decimal-input label="Auxílio-transporte mensal (R$) (opcional)" error:name="transportation_allowance" placeholder="0,00" /></div>
        <flux:text size="sm">A bolsa deve ser positiva. O auxílio pode ficar vazio ou ser zero.</flux:text>
    @else
        <flux:text size="sm">Sem remuneração, os valores de bolsa e auxílio ficam vazios.</flux:text>
    @endif
@elseif ($step === 8)
    @include('pages.internship-requests.partials.review')
@endif
