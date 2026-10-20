<?php

use App\Actions\ReviewEmancipationEvidence;
use App\Actions\ReviewInternshipRegistration;
use App\Actions\ReviewInternshipRequest;
use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\GrantingParty;
use App\Models\InternshipRequest;
use App\Support\ActivityAccess;
use App\Support\ActivityHistory;
use App\Support\StudentInternshipContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Análise da solicitação de estágio')] class extends Component
{
    use WithPagination;

    #[Locked] public int $requestId;
    #[Locked] public int $affiliationId;
    public string $message = '';
    public array $sections = [];
    public array $sectionMessages = [];
    public ?int $advisorId = null;
    public ?int $partyId = null;
    public ?int $supervisorId = null;
    public ?int $supervisorQualificationId = null;
    public string $evidenceReason = '';

    public function mount(InternshipRequest $internshipRequest, StudentInternshipContext $context): void
    {
        Gate::authorize('review', $internshipRequest);
        $this->requestId = $internshipRequest->id;
        $this->advisorId = $internshipRequest->internship()->first()?->advisor_affiliation_id ?? $internshipRequest->advisor_affiliation_id;
        $this->partyId = $internshipRequest->granting_party_id;
        $this->supervisorId = $internshipRequest->supervisor_affiliation_id;
        $openCorrection = $internshipRequest->corrections()->where('status', 'open')->first();
        $this->sections = $openCorrection?->affected_sections ?? [];
        $this->sectionMessages = $openCorrection?->section_messages ?? [];
        $this->message = $openCorrection?->general_message ?? '';
        $this->affiliationId = $context->selected(Auth::user())->id;
        $this->synchronizeSupervisorQualification();
    }

    public function hydrate(StudentInternshipContext $context): void
    {
        abort_unless($context->selected(Auth::user())?->id === $this->affiliationId, 403);
        Gate::authorize('review', $this->record);
    }

    #[Computed]
    public function record(): InternshipRequest
    {
        $request = InternshipRequest::query()->with(['internship', 'advisorAffiliation.user', 'course', 'internshipType', 'studentAddress.city', 'workplaceAddress.city',
            'grantingPartyRegistrationRequest', 'supervisorRegistrationRequest', 'emancipationEvidences' => fn ($query) => $query->latest('id'),
            'corrections' => fn ($query) => $query->latest('id')])->findOrFail($this->requestId);
        Gate::authorize('review', $request);
        if ($request->grantingPartyRegistrationRequest === null && isset($request->granting_party_snapshot['source_registration_request_id'])) {
            $request->setRelation('grantingPartyRegistrationRequest', \App\Models\GrantingPartyRegistrationRequest::query()->whereKey($request->granting_party_snapshot['source_registration_request_id'])->first());
        }
        if ($request->supervisorRegistrationRequest === null && isset($request->supervisor_snapshot['source_registration_request_id'])) {
            $request->setRelation('supervisorRegistrationRequest', \App\Models\SupervisorRegistrationRequest::query()->whereKey($request->supervisor_snapshot['source_registration_request_id'])->first());
        }
        return $request;
    }

    /** @return LengthAwarePaginator<int, array{id: int, actor: string, subject: string, event: string, occurred_at: string}>|null */
    #[Computed]
    public function activityHistory(): ?LengthAwarePaginator
    {
        Gate::authorize('review', $this->record);
        $query = app(ActivityAccess::class)->forCurrentContext(Auth::user(), app('session.store'));

        return $query === null ? null : app(ActivityHistory::class)->forSubject($this->record, $query);
    }

    #[Computed]
    public function choices(): array
    {
        $campusId = app(StudentInternshipContext::class)->selected(Auth::user())->campus_id;
        return ['qualifications' => \App\Models\SupervisorQualification::query()->where('user_id', $this->record->supervisorAffiliation?->user_id)->get(),
            'parties' => GrantingParty::query()->active()->where('campus_id', $campusId)->orderBy('name')->get(),
            'supervisors' => Affiliation::query()->active()->where('campus_id', $campusId)->where('type', AffiliationType::Supervisor->value)->with('user')->get(),
            'advisors' => Affiliation::query()->active()->where('campus_id', $campusId)->where('type', AffiliationType::Advisor->value)->with('user')->get()];
    }

    #[Computed]
    public function canDecide(): bool
    {
        return app(StudentInternshipContext::class)->selected(Auth::user())?->campus?->deactivated_at === null;
    }

    public function decide(string $operation, ReviewInternshipRequest $action): void
    {
        $result = $action->handle(Auth::user(), $this->record, $operation, $this->message, $this->sections, $this->advisorId, $this->supervisorQualificationId, $this->sectionMessages);
        unset($this->record, $this->choices, $this->activityHistory);
        \Flux\Flux::toast($operation === 'accept' ? 'Solicitação aceita. Estágio nº '.$result->internship_id.' em formalização.' : 'Decisão registrada.', variant: 'success');
    }

    public function refreshChoices(): void
    {
        unset($this->choices);
        $this->synchronizeSupervisorQualification();
    }

    private function synchronizeSupervisorQualification(): void
    {
        $qualifications = $this->choices['qualifications'];

        if ($qualifications->count() === 1) {
            $this->supervisorQualificationId = $qualifications->first()->id;

            return;
        }

        if ($this->supervisorQualificationId !== null && ! $qualifications->contains('id', $this->supervisorQualificationId)) {
            $this->supervisorQualificationId = null;
        }
    }

    public function updatedSections(): void
    {
        $this->sections = array_values(array_filter($this->sections, fn (mixed $section): bool => is_string($section)));
        $this->sectionMessages = array_intersect_key($this->sectionMessages, array_flip($this->sections));
        $this->resetValidation('sectionMessages');
    }

    public function registration(string $kind, bool $approve, ReviewInternshipRegistration $action): void
    {
        $id = $kind === 'granting_party' ? $this->partyId : $this->supervisorId;
        if ($approve && $id === null) {
            $this->addError('registration', 'Selecione o cadastro que será associado.');
            return;
        }
        $action->handle(Auth::user(), $this->record, $kind, $approve ? $id : null, $this->message ?: null);
        unset($this->record, $this->choices, $this->activityHistory);
        \Flux\Flux::toast('Análise do cadastro registrada. Os dados propostos foram preservados.', variant: 'success');
    }

    public function evidence(int $id, bool $approve, ReviewEmancipationEvidence $action): void
    {
        $evidence = $this->record->emancipationEvidences->firstWhere('id', $id);
        abort_if($evidence === null, 404);
        $action->handle(Auth::user(), $evidence, $approve, $this->evidenceReason ?: null);
        unset($this->record, $this->choices, $this->activityHistory);
        \Flux\Flux::toast('Análise do comprovante registrada.', variant: 'success');
    }
}; ?>
@php
    $request = $this->record;
    $values = $request->only(['student_year_semester', 'co_advisor_name', 'internship_sector', 'activities', 'observations', 'weekly_hours', 'planned_pauses', 'is_remunerated', 'grant_value', 'transportation_allowance', 'legal_guardian_name', 'legal_guardian_cpf', 'legal_guardian_kinship', 'legal_guardian_email', 'internship_type_id', 'advisor_affiliation_id']);
    $values['legal_capacity_declaration'] = $request->legal_capacity_declaration?->value;
    $values['planned_start_date'] = $request->planned_start_date?->toDateString();
    $pendingParty = $request->granting_party_registration_request_id !== null;
    $pendingSupervisor = $request->supervisor_registration_request_id !== null;
    $personal = [];
    $choices = [];
@endphp
<div class="w-full max-w-none space-y-6" x-on:focus.window="$wire.refreshChoices()">
    <flux:breadcrumbs><flux:breadcrumbs.item :href="route('internship-review.index')" wire:navigate>Analisar solicitações</flux:breadcrumbs.item><flux:breadcrumbs.item>Nº {{ $request->id }}</flux:breadcrumbs.item></flux:breadcrumbs>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <flux:heading level="1" size="xl">Solicitação nº {{ $request->id }} · {{ $request->status->label() }}</flux:heading>
        @if ($request->internship !== null)
            @can('view', $request->internship)
                <flux:button icon="briefcase" :href="route('internships.show', $request->internship)" wire:navigate>Ver estágio nº {{ $request->internship->id }}</flux:button>
            @endcan
        @endif
    </div>
    @if ($errors->any())<flux:callout variant="danger" heading="Confira a decisão"><ul>@foreach($errors->all() as $index => $error)<li wire:key="review-error-{{ $index }}">{{ $error }}</li>@endforeach</ul></flux:callout>@endif
    <div class="space-y-6 rounded-xl border border-zinc-200 p-6 text-base dark:border-zinc-700">@include('pages.internship-requests.partials.review')</div>
    @foreach (['granting_party' => $request->grantingPartyRegistrationRequest, 'supervisor' => $request->supervisorRegistrationRequest] as $kind => $proposal)
        @if ($proposal)
            <section wire:key="registration-{{ $kind }}" class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading level="2">Pedido de cadastro: {{ $kind === 'granting_party' ? 'concedente' : 'supervisor' }} · {{ $proposal->status->label() }}</flux:heading>
                <flux:text size="lg">Revise a proposta acima. Se necessário, crie o cadastro pela gestão existente e depois associe-o aqui. A associação exige um cadastro ativo do mesmo campus.</flux:text>
                @if ($kind === 'granting_party')
                    @if ($this->canDecide && $pendingParty && in_array($request->status->value, ['submitted', 'under_review', 'pending_correction'], true) && in_array($proposal->status->value, ['submitted', 'under_review'], true))
                        <flux:button :href="route('granting-parties.create', ['internship_request' => $request->id])" icon="building-office" target="_blank" rel="noopener">Cadastrar concedente com os dados do pedido</flux:button>
                        <flux:text>Ao voltar para esta tela, a lista será atualizada automaticamente. Selecione o cadastro criado e aprove a associação.</flux:text>
                    @endif
                    <x-select wire:model="partyId" :value="$partyId ?? ''" label="Concedente aprovada" placeholder="Selecione" :options="$this->choices['parties']->pluck('name', 'id')->all()" :invalid="$errors->has('partyId')" :error="$errors->first('partyId')" searchable />
                    @if ($pendingParty && $this->canDecide && in_array($proposal->status->value, ['submitted', 'under_review'], true))
                        <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refreshChoices">Atualizar lista de concedentes</flux:button>
                    @endif
                @else
                    <flux:text>CPF proposto: {{ \App\Helpers\BrazilianDocumentHelper::formatCpf($proposal->cpf) }}</flux:text>
                    @if ($this->canDecide && $pendingSupervisor && in_array($request->status->value, ['submitted', 'under_review', 'pending_correction'], true) && in_array($proposal->status->value, ['submitted', 'under_review'], true))
                        <flux:button :href="route('users.create', ['internship_request' => $request->id])" icon="user-plus" target="_blank" rel="noopener">Cadastrar supervisor com os dados do pedido</flux:button>
                        <flux:text>O CPF será consultado e os dados do pedido preencherão o cadastro para revisão. Ao voltar para esta tela, a lista será atualizada automaticamente; selecione o vínculo criado e aprove a associação.</flux:text>
                    @endif
                    <x-select wire:model="supervisorId" :value="$supervisorId ?? ''" label="Vínculo de supervisor aprovado" placeholder="Selecione" :options="$this->choices['supervisors']->mapWithKeys(fn ($supervisor) => [$supervisor->id => $supervisor->user->name.' - '.$supervisor->email])->all()" :invalid="$errors->has('supervisorId')" :error="$errors->first('supervisorId')" searchable />
                    @if ($pendingSupervisor && $this->canDecide && in_array($proposal->status->value, ['submitted', 'under_review'], true))
                        <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refreshChoices">Atualizar lista de supervisores</flux:button>
                    @endif
                @endif
                @if ($this->canDecide && in_array($proposal->status->value, ['submitted', 'under_review'], true))
                    <div class="flex flex-wrap gap-3"><flux:button wire:click="registration('{{ $kind }}', true)" wire:loading.attr="disabled">Aprovar e associar cadastro</flux:button><flux:button variant="danger" wire:click="registration('{{ $kind }}', false)" wire:loading.attr="disabled">Recusar pedido com o motivo abaixo</flux:button></div>
                @endif
                @if($proposal->decision_reason)<flux:text>{{ $proposal->decision_reason }}</flux:text>@endif
            </section>
        @endif
    @endforeach
    @foreach ($request->emancipationEvidences as $evidence)
        <section wire:key="review-evidence-{{ $evidence->id }}" class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading level="2">Comprovante de emancipação · {{ $evidence->status->label() }}</flux:heading>
            <flux:button :href="route('internship-requests.evidence', $evidence)" target="_blank" rel="noopener">Abrir comprovante privado</flux:button>
            @if ($this->canDecide && $loop->first && $evidence->status->value === 'submitted')
                <flux:textarea wire:model="evidenceReason" label="O que precisa ser corrigido no comprovante?" rows="3" maxlength="5000" />
                <div class="flex gap-3"><flux:button wire:click="evidence({{ $evidence->id }}, true)" wire:loading.attr="disabled">Aprovar comprovante</flux:button><flux:button variant="danger" wire:click="evidence({{ $evidence->id }}, false)" wire:loading.attr="disabled">Devolver comprovante</flux:button></div>
            @endif
        </section>
    @endforeach
    @if ($this->canDecide)
    <section class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <flux:heading level="2">Decisão do Setor de Estágio</flux:heading>
        @if ($request->internship !== null)
            @php
                $historicalQualification = $request->internship->supervisor_snapshot ?? [];
            @endphp
            <flux:callout heading="Qualificação registrada no estágio">
                <flux:callout.text>{{ (bool) ($historicalQualification['has_academic_background'] ?? false)
                    ? (\App\Enums\EducationLevel::tryFrom($historicalQualification['education_level'] ?? '')?->label().' · '.($historicalQualification['training_course'] ?? ''))
                    : (($historicalQualification['experience_area'] ?? '').' · '.($historicalQualification['experience_time'] ?? '')) }}</flux:callout.text>
            </flux:callout>
        @endif
        @if ($request->supervisor_affiliation_id !== null)
            <x-select wire:model="supervisorQualificationId" :value="$supervisorQualificationId ?? ''" label="Qualificação do supervisor para este estágio" placeholder="Selecione a formação ou experiência adequada" :options="$this->choices['qualifications']->mapWithKeys(fn ($qualification) => [$qualification->id => $qualification->label()])->all()" :error="$errors->first('supervisorQualificationId')" />
            <flux:text size="sm">A escolha será copiada para o histórico do estágio ao aceitar a solicitação.</flux:text>
            @if ($this->choices['qualifications']->isEmpty())
                <flux:callout variant="warning">O supervisor ainda não possui formações ou experiências cadastradas. Atualize o cadastro antes de formalizar o estágio.</flux:callout>
                <flux:button :href="route('users.show', $request->supervisorAffiliation->user_id)" target="_blank" rel="noopener">Abrir cadastro do supervisor</flux:button>
            @endif
        @endif
        <flux:callout variant="info" icon="information-circle" heading="Devolva com orientações específicas">
            <flux:callout.text>Marque cada etapa que o discente deve corrigir e escreva uma mensagem própria para cada uma. Essas mensagens aparecerão separadas na solicitação e no e-mail de aviso.</flux:callout.text>
        </flux:callout>
        <flux:textarea wire:model="message" label="Motivo geral da decisão" description="Obrigatório ao recusar. Na devolução para correção, as mensagens por etapa são obrigatórias; este comentário geral é opcional." rows="3" maxlength="5000" />
        <flux:checkbox.group wire:model.live="sections" label="Etapas que precisam de correção">
            @foreach (['personal_data' => 'Dados pessoais', 'internship' => 'Tipo, ano/semestre e orientador', 'granting_party' => 'Concedente', 'supervisor' => 'Supervisor', 'activities' => 'Atividades e setor', 'schedule' => 'Jornada e pausas previstas', 'planned_start_date' => 'Data de início', 'legal_capacity' => 'Capacidade civil e comprovante', 'remuneration' => 'Remuneração'] as $value => $label)<flux:checkbox :value="$value" :label="$label" wire:key="correction-section-{{ $value }}" />@endforeach
        </flux:checkbox.group>
        @foreach ($sections as $section)
            <div wire:key="correction-message-{{ $section }}" class="rounded-xl border border-amber-300 bg-amber-50/70 p-4 dark:border-amber-800 dark:bg-amber-950/30">
                <flux:textarea wire:model="sectionMessages.{{ $section }}" :label="'O que o discente precisa corrigir em '.\App\Models\InternshipRequestCorrection::sectionLabel($section).'?'" rows="3" maxlength="5000" required error:name="sectionMessages.{{ $section }}" />
            </div>
        @endforeach
        <x-select wire:model="advisorId" :value="$advisorId ?? ''" label="Orientador" placeholder="Selecione" :options="$this->choices['advisors']->mapWithKeys(fn ($advisor) => [$advisor->id => $advisor->user->name.' - '.$advisor->email])->all()" :invalid="$errors->has('advisorId') || $errors->has('advisor_affiliation_id')" :error="$errors->first('advisorId') ?: $errors->first('advisor_affiliation_id')" searchable />
        @if (in_array($request->status->value, ['submitted', 'under_review', 'accepted', 'pending_correction'], true))
            <div class="flex flex-wrap gap-3">
                @if (in_array($request->status->value, ['submitted', 'under_review'], true))<flux:button wire:click="decide('begin')" wire:loading.attr="disabled">Iniciar análise</flux:button>@endif
                <flux:button wire:click="decide('return')" wire:loading.attr="disabled">Devolver para correção</flux:button>
                @if (in_array($request->status->value, ['submitted', 'under_review'], true))<flux:button variant="primary" wire:click="decide('accept')" wire:loading.attr="disabled">Aceitar e formalizar</flux:button>@endif
                @if ($request->internship_id === null && in_array($request->status->value, ['submitted', 'under_review'], true))<flux:button variant="danger" wire:click="decide('reject')" wire:loading.attr="disabled">Recusar com motivo</flux:button>@endif
            </div>
        @endif
    </section>
    @else
        <flux:callout>O campus está desativado. A solicitação está disponível somente para consulta.</flux:callout>
    @endif
    <x-audit.activity-history :activities="$this->activityHistory" description="Alterações na solicitação, correções, pedidos de cadastro e decisões sobre emancipação. Selecione um evento para conferir os valores registrados." />
</div>
