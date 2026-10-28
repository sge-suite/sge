<?php

use App\Actions\PreviewInternshipCalendar;
use App\Actions\UpdateInternshipData;
use App\Concerns\InteractsWithAddressLookup;
use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\GrantingParty;
use App\Models\Internship;
use App\Models\InternshipType;
use App\Models\SupervisorQualification;
use App\Support\InternshipCalendarOverrides;
use App\Support\InternshipPlannedPauses;
use App\Support\InternshipRequestSnapshots;
use App\Support\StudentInternshipContext;
use Flux\Flux;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

{
new #[Title('Editar estágio')] class extends Component
    use \App\Concerns\SearchesSelectOptions;

    protected function selectSearchSource(string $source): array
    {
        $model = match ($source) {
            'types' => InternshipType::class,
            'parties' => GrantingParty::class,
            'advisors', 'supervisors' => Affiliation::class,
            default => abort(404),
        };

        $configuration = ['model' => $model, 'options' => $this->choices[$source]];
        if ($source === 'parties') {
            $configuration['attributes'] = ['name', 'document_number', 'normalized_document_number'];
        }

        return $configuration;
    }

    use InteractsWithAddressLookup {
        applyCepLookup as protected applyInternshipCepLookup;
    }

    #[Locked] public int $internshipId;
    #[Locked] public int $affiliationId;
    #[Locked] public string $expectedUpdatedAt;
    #[Locked] public ?string $previewEndDate = null;
    #[Locked] public bool $hasProjectionChanges = false;
    /** @var array<string, mixed>|null */
    #[Locked] public ?array $calendarResult = null;
    #[Locked] public bool $calendarIsSaved = false;
    public bool $showCalendar = false;
    public string $periodNumber = '';
    public string $periodUnit = 'semester';

    public function mount(Internship $internship, StudentInternshipContext $context): void
    {
        Gate::authorize('update', $internship);
        $this->internshipId = $internship->id;
        $this->affiliationId = $context->selected(Auth::user())->id;
        $this->fillFromInternship($internship);
    }

    private function fillFromInternship(Internship $internship): void
    {
        $this->expectedUpdatedAt = $internship->getRawOriginal('updated_at');
        $address = $internship->workplaceAddress()->firstOrFail();
        $this->selectedCityId = $address->city_id;
        $this->values = $internship->only(['internship_type_id', 'advisor_affiliation_id', 'co_advisor_name', 'supervisor_affiliation_id', 'granting_party_id',
            'activities', 'internship_sector', 'observations', 'is_remunerated', 'grant_value', 'transportation_allowance', 'weekly_hours']);
        $this->values += ['rules' => Arr::only($internship->internship_type_snapshot['rules'] ?? [], InternshipRequestSnapshots::TYPE_RULE_FIELDS),
            'planned_start_date' => $internship->planned_start_date->toDateString(), 'planned_pauses' => $internship->planned_pauses ?? [],
            'student_year_semester' => $internship->student_snapshot['student_year_semester'] ?? '',
            'party' => array_replace(array_fill_keys(UpdateInternshipData::PARTY_FIELDS, ''), Arr::only($internship->granting_party_snapshot, UpdateInternshipData::PARTY_FIELDS)),
            'supervisor' => array_replace(['job_role' => '', 'phone' => '', 'email' => ''], Arr::only($internship->supervisor_snapshot, ['job_role', 'phone', 'email'])),
            'supervisor_qualification_id' => $internship->supervisor_snapshot['source_qualification_id'] ?? null,
            'address' => $address->only(['street', 'number', 'neighborhood', 'zip_code', 'city_id']), 'change_reason' => '',
            'calendar_overrides' => app(InternshipCalendarOverrides::class)->values($internship)];
    }

    public function hydrate(StudentInternshipContext $context): void
    {
        abort_unless($context->selected(Auth::user())?->id === $this->affiliationId, 403);
        Gate::authorize('update', $this->record);
    }

    protected function authorizeAddressLookup(): void
    {
        Gate::authorize('updateAgreement', $this->record);
    }

    #[Computed]
    public function record(): Internship
    {
        $internship = Internship::query()->with(['course', 'workplaceAddress.city'])->findOrFail($this->internshipId);
        Gate::authorize('update', $internship);

        return $internship;
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function choices(): array
    {
        $campusId = $this->record->course->campus_id;
        $affiliations = Affiliation::query()->where('campus_id', $campusId)
            ->whereIn('type', [AffiliationType::Advisor, AffiliationType::Supervisor])
            ->where(fn ($query) => $query->whereNull('deactivated_at')->orWhereIn('id', [$this->record->advisor_affiliation_id, $this->record->supervisor_affiliation_id]))
            ->with('user')->orderBy('id')->get()->sortBy(fn (Affiliation $affiliation) => $affiliation->user->name);
        $types = InternshipType::query()->where('course_id', $this->record->course_id)
            ->where(fn ($query) => $query->whereNull('deactivated_at')->orWhere('id', $this->record->internship_type_id))->orderBy('name')->orderBy('id')->get();
        $parties = GrantingParty::query()->where('campus_id', $campusId)
            ->where(fn ($query) => $query->whereNull('deactivated_at')->orWhere('id', $this->record->granting_party_id))->orderBy('name')->orderBy('id')->get();
        $supervisor = $affiliations->firstWhere('id', (int) ($this->values['supervisor_affiliation_id'] ?? 0));
        $qualifications = $supervisor === null ? collect() : SupervisorQualification::query()->where('user_id', $supervisor->user_id)->orderBy('id')->get();
        $qualificationOptions = $qualifications->mapWithKeys(fn (SupervisorQualification $qualification): array => [$qualification->id => $qualification->label()])->all();
        $recordedQualification = $this->record->supervisor_snapshot['source_qualification_id'] ?? null;
        if ($supervisor?->id === $this->record->supervisor_affiliation_id && $recordedQualification !== null && ! array_key_exists($recordedQualification, $qualificationOptions)) {
            $qualificationOptions[$recordedQualification] = 'Qualificação registrada na abertura';
        }

        return ['advisors' => $affiliations->where('type', AffiliationType::Advisor)->mapWithKeys(fn (Affiliation $affiliation): array => [$affiliation->id => $affiliation->user->name.($affiliation->deactivated_at !== null ? ' (desativado)' : '')])->all(),
            'supervisors' => $affiliations->where('type', AffiliationType::Supervisor)->mapWithKeys(fn (Affiliation $affiliation): array => [$affiliation->id => $affiliation->user->name.($affiliation->deactivated_at !== null ? ' (desativado)' : '')])->all(),
            'types' => $types->mapWithKeys(fn (InternshipType $type): array => [$type->id => $type->name.($type->deactivated_at !== null ? ' (desativado)' : '')])->all(),
            'parties' => $parties->mapWithKeys(fn (GrantingParty $party): array => [$party->id => $party->name.($party->deactivated_at !== null ? ' (desativada)' : '')])->all(),
            'selectedType' => $types->firstWhere('id', (int) ($this->values['internship_type_id'] ?? 0)), 'qualifications' => $qualificationOptions,
            'selectedParty' => $parties->firstWhere('id', (int) ($this->values['granting_party_id'] ?? 0))];
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function typeRules(): array
    {
        return array_replace($this->values['rules'] ?? [], ['excellent' => $this->values['rules']['supervisor_evaluation_weight'] ?? '',
            'unsatisfactory' => $this->values['rules']['unsatisfactory_value'] ?? '']);
    }

    /** @return array<string, string> */
    #[Computed]
    public function periodOptions(): array
    {
        $periods = $this->typeRules['allowed_academic_periods'] ?? [];
        $recorded = $this->record->student_snapshot['student_year_semester'] ?? null;
        if ((int) ($this->values['internship_type_id'] ?? 0) === $this->record->internship_type_id && is_string($recorded) && $recorded !== ''
            && $periods === ($this->record->internship_type_snapshot['rules']['allowed_academic_periods'] ?? [])) {
            $periods[] = $recorded;
        }

        return array_combine($periods, $periods);
    }

    public function updatedSelectedCityId(): void
    {
        $this->values['address']['city_id'] = $this->selectedCityId;
        $this->updateProjection();
    }

    public function applyCepLookup(): void
    {
        $this->applyInternshipCepLookup();
        $this->updateProjection();
    }

    public function updatedValues(mixed $value, ?string $key = null): void
    {
        if ($key === null) {
            unset($this->choices, $this->typeRules, $this->periodOptions);
            $this->updateProjection();

            return;
        }
        $selectedId = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if (in_array($key, ['internship_type_id', 'granting_party_id', 'supervisor_affiliation_id'], true) && $selectedId === false) {
            unset($this->choices, $this->typeRules, $this->periodOptions);
            if ($key === 'internship_type_id') {
                $this->values['rules'] = [];
                $this->updateProjection();
            }

            return;
        }
        if ($key === 'internship_type_id') {
            unset($this->choices);
            $type = $this->choices['selectedType'];
            $snapshot = (int) $value === $this->record->internship_type_id ? $this->record->internship_type_snapshot
                : ($type === null ? [] : app(InternshipRequestSnapshots::class)->type($type));
            $this->values['rules'] = Arr::only($snapshot['rules'] ?? [], InternshipRequestSnapshots::TYPE_RULE_FIELDS);
        } elseif ($key === 'granting_party_id') {
            $party = GrantingParty::query()->where('campus_id', $this->record->course->campus_id)
                ->where(fn ($query) => $query->whereNull('deactivated_at')->orWhere('id', $this->record->granting_party_id))
                ->whereKey($selectedId)->with('address')->first();
            if ($party !== null) {
                $snapshot = (int) $value === $this->record->granting_party_id ? $this->record->granting_party_snapshot : app(InternshipRequestSnapshots::class)->party($party);
                $this->values['party'] = array_replace(array_fill_keys(UpdateInternshipData::PARTY_FIELDS, ''), Arr::only($snapshot, UpdateInternshipData::PARTY_FIELDS));
                if (Gate::allows('updateAgreement', $this->record)) {
                    $address = (int) $value === $this->record->granting_party_id ? $this->record->workplaceAddress : $party->address;
                    $this->values['address'] = $address->only(['street', 'number', 'neighborhood', 'zip_code', 'city_id']);
                    $this->selectedCityId = $address->city_id;
                    $this->citySelectionVersion++;
                }
            }
        } elseif ($key === 'supervisor_affiliation_id') {
            $supervisor = Affiliation::query()->where('campus_id', $this->record->course->campus_id)->where('type', AffiliationType::Supervisor)
                ->where(fn ($query) => $query->whereNull('deactivated_at')->orWhere('id', $this->record->supervisor_affiliation_id))
                ->whereKey($selectedId)->first();
            if ($supervisor !== null) {
                $snapshot = $supervisor->id === $this->record->supervisor_affiliation_id ? $this->record->supervisor_snapshot : app(InternshipRequestSnapshots::class)->supervisor($supervisor, []);
                $this->values['supervisor'] = array_replace(['job_role' => '', 'phone' => '', 'email' => ''], Arr::only($snapshot, ['job_role', 'phone', 'email']));
                $this->values['supervisor_qualification_id'] = $snapshot['source_qualification_id'] ?? null;
            }
        }
        if (in_array($key, ['internship_type_id', 'granting_party_id', 'supervisor_affiliation_id'], true)) {
            unset($this->choices, $this->typeRules, $this->periodOptions);
        }
        if ($key === 'rules' || str_starts_with($key, 'rules.')) {
            unset($this->typeRules, $this->periodOptions);
            $this->resetValidation($key);
        }
        if ($key === 'internship_type_id' && ! array_key_exists($this->values['student_year_semester'], $this->periodOptions)) {
            $this->values['student_year_semester'] = '';
        }
        if (in_array($key, ['internship_type_id', 'planned_start_date', 'granting_party_id', 'address.city_id', 'weekly_hours', 'planned_pauses', 'calendar_overrides', 'rules',
            'rules.required_hours', 'rules.safety_margin_days', 'rules.max_daily_hours', 'rules.max_weekly_hours'], true)
            || str_starts_with($key, 'weekly_hours.') || str_starts_with($key, 'planned_pauses.') || str_starts_with($key, 'calendar_overrides.')) {
            $this->updateProjection();
        }
    }

    public function previewCalendar(): void
    {
        $this->updateProjection(includeCalendar: true);
    }

    public function addCalendarOverride(): void
    {
        Gate::authorize('manageCalendar', $this->record);
        if (count($this->values['calendar_overrides']) < 3661) {
            $this->values['calendar_overrides'][] = ['id' => null, 'date' => '', 'is_working_day' => false, 'reason' => ''];
            $this->hasProjectionChanges = true;
            $this->previewEndDate = null;
            $this->calendarResult = null;
            $this->showCalendar = false;
        }
    }

    public function removeNewCalendarOverride(int $index): void
    {
        Gate::authorize('manageCalendar', $this->record);
        abort_unless(isset($this->values['calendar_overrides'][$index]) && $this->values['calendar_overrides'][$index]['id'] === null, 422);
        array_splice($this->values['calendar_overrides'], $index, 1);
        $this->updateProjection();
    }

    public function addAcademicPeriod(): void
    {
        Gate::authorize('update', $this->record);
        $this->validate(['periodNumber' => ['required', 'integer', 'min:1', 'max:99'],
            'periodUnit' => ['required', \Illuminate\Validation\Rule::in(['year', 'semester'])]],
            attributes: ['periodNumber' => 'número do ano ou semestre', 'periodUnit' => 'tipo de período']);
        $period = (int) $this->periodNumber.'º '.($this->periodUnit === 'year' ? 'ano' : 'semestre');
        $periods = $this->values['rules']['allowed_academic_periods'] ?? [];
        if (in_array($period, $periods, true) || count($periods) >= 50) {
            $this->addError('periodNumber', 'Adicione até 50 períodos, sem repetir opções.');

            return;
        }
        $this->values['rules']['allowed_academic_periods'][] = $period;
        $this->periodNumber = '';
        unset($this->typeRules, $this->periodOptions);
        $this->resetValidation(['rules.allowed_academic_periods', 'periodNumber']);
    }

    public function removeAcademicPeriod(int $index): void
    {
        Gate::authorize('update', $this->record);
        abort_unless(isset($this->values['rules']['allowed_academic_periods'][$index]), 422);
        array_splice($this->values['rules']['allowed_academic_periods'], $index, 1);
        unset($this->typeRules, $this->periodOptions);
        if (! array_key_exists($this->values['student_year_semester'], $this->periodOptions)) {
            $this->values['student_year_semester'] = '';
        }
        $this->resetValidation('rules.allowed_academic_periods');
    }

    private function updateProjection(bool $includeCalendar = false): void
    {
        $this->calendarIsSaved = false;
        $this->hasProjectionChanges = true;
        $this->previewEndDate = null;
        $this->calendarResult = null;
        $this->showCalendar = false;
        foreach (array_keys($this->getErrorBag()->getMessages()) as $field) {
            if (in_array($field, ['projected_end_date', 'internship_type_id', 'planned_start_date', 'address.city_id', 'weekly_hours', 'planned_pauses'], true)
                || $field === 'rules' || str_starts_with($field, 'rules.') || str_starts_with($field, 'weekly_hours.') || str_starts_with($field, 'planned_pauses.')
                || $field === 'calendar_overrides' || str_starts_with($field, 'calendar_overrides.')) {
                $this->resetValidation($field);
            }
        }
        try {
            $result = app(PreviewInternshipCalendar::class)->handle(Auth::user(), $this->record, [
                'internship_type_id' => $this->values['internship_type_id'] ?? null, 'planned_start_date' => $this->values['planned_start_date'] ?? null,
                'rules' => $this->values['rules'] ?? [],
                'calendar_overrides' => $this->values['calendar_overrides'] ?? [],
                'weekly_hours' => $this->values['weekly_hours'] ?? null, 'planned_pauses' => $this->values['planned_pauses'] ?? [], 'city_id' => $this->selectedCityId,
            ], $includeCalendar);
            $this->previewEndDate = $result['projected_end_date'];
            if ($includeCalendar) {
                $this->calendarResult = $result;
                $this->showCalendar = true;
            }
        } catch (ValidationException $exception) {
            if (! $includeCalendar) {
                return;
            }
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field === 'city_id' ? 'address.city_id' : $field, $messages[0]);
            }
            if (! $this->getErrorBag()->has('projected_end_date')) {
                $this->addError('projected_end_date', 'Ajuste os campos indicados para atualizar a previsão e consultar o calendário.');
            }
        }
    }

    public function addPlannedPause(): void
    {
        Gate::authorize('updateAgreement', $this->record);
        if (count($this->values['planned_pauses']) < InternshipPlannedPauses::MAX_PERIODS) {
            $this->values['planned_pauses'][] = ['starts_at' => '', 'ends_at' => '', 'reason' => ''];
            $this->updateProjection();
        }
    }

    public function removePlannedPause(int $index): void
    {
        Gate::authorize('updateAgreement', $this->record);
        unset($this->values['planned_pauses'][$index]);
        $this->values['planned_pauses'] = array_values($this->values['planned_pauses']);
        $this->resetValidation('planned_pauses');
        $this->updateProjection();
    }

    public function save(UpdateInternshipData $update): void
    {
        $this->values['address']['city_id'] = $this->selectedCityId;
        $internship = $update->handle(Auth::user(), $this->record, $this->affiliationId, $this->expectedUpdatedAt, $this->values, recalculate: $this->hasProjectionChanges);
        $this->fillFromInternship($internship);
        unset($this->record, $this->choices, $this->typeRules, $this->periodOptions);
        $this->citySelectionVersion++;
        $this->cancelCepLookup();
        $this->periodNumber = '';
        $this->hasProjectionChanges = false;
        $this->previewEndDate = null;
        $this->calendarResult = null;
        $this->calendarIsSaved = false;
        $this->showCalendar = false;
        $this->resetValidation();
        if ($internship->endDateCalculation !== null) {
            $result = $internship->endDateCalculation;
            $city = $this->record->workplaceAddress->city;
            $this->calendarResult = array_replace($result, ['completion_date' => $result['completion_date']->toDateString(),
                'projected_end_date' => $result['projected_end_date']->toDateString(), 'saved_projected_end_date' => $internship->projected_end_date->toDateString(),
                'margin_days' => (int) $internship->internship_type_snapshot['rules']['safety_margin_days'], 'location' => $city->name.' / '.$city->state->value]);
            $this->calendarIsSaved = true;
            $this->showCalendar = true;
        }
        Flux::toast('Estágio atualizado com sucesso.', variant: 'success');
        $this->dispatch('form-saved', scope: 'internship-edit', savedAt: formatDateTime(now()));
    }
}; ?>

<div class="w-full space-y-8">
    @php
        $record = $this->record;
        $choices = $this->choices;
        $typeRules = $this->typeRules;
        $canEditAgreement = auth()->user()->can('updateAgreement', $record);
        $canManageCalendar = auth()->user()->can('manageCalendar', $record);
        $partyDocument = (int) ($values['granting_party_id'] ?? 0) === $record->granting_party_id ? ($record->granting_party_snapshot['document_number'] ?? '') : $choices['selectedParty']?->document_number;
    @endphp
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('internships.index')" wire:navigate>Estágios</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('internships.show', $record)" wire:navigate>Nº {{ $record->id }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Editar</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <form wire:submit="save" class="space-y-8" novalidate data-unsaved-changes="internship-edit" data-unsaved-models='["values", "selectedCityId"]' data-unsaved-actions='["save"]'>
        <x-form-save-status :saved-at="$this->record->updated_at" />
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div><flux:heading size="xl" level="1">Editar estágio nº {{ $record->id }}</flux:heading><flux:text class="mt-2">Atualize os dados do estágio de {{ $record->student_snapshot['name'] }}. As alterações só são gravadas ao clicar em Salvar alterações.</flux:text></div>
            <div class="flex flex-wrap items-center gap-3">
                <flux:button variant="outline" :href="route('internships.show', $record)" wire:navigate>Cancelar</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Salvar alterações</flux:button>
            </div>
        </div>
        <flux:error name="internship" />
        <section class="border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="edit-student-heading">
            <flux:heading id="edit-student-heading" size="lg" level="2">Discente</flux:heading>
            <div class="mt-5 grid items-start gap-5 sm:grid-cols-2">
                <flux:input label="Discente" :value="$record->student_snapshot['name']" readonly />
                <flux:input label="Matrícula" :value="$record->student_snapshot['registration_number'] ?? ''" readonly />
                <flux:input label="Curso" :value="$record->course->name" readonly />
                <x-select wire:model="values.student_year_semester" :value="$values['student_year_semester']" label="Ano ou semestre" :options="$this->periodOptions" :error="$errors->first('student_year_semester')" placeholder="Selecione o ano ou semestre" required />
            </div>
        </section>
        <section class="border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="edit-data-heading">
            <flux:heading id="edit-data-heading" size="lg" level="2">Dados do estágio</flux:heading>
            <div class="mt-5 grid items-start gap-5 sm:grid-cols-2">
                <x-select search-source="types" wire:model.live="values.internship_type_id" :value="$values['internship_type_id']" label="Tipo de estágio" :options="$choices['types']" :error="$errors->first('internship_type_id')" :disabled="! $canEditAgreement" searchable required />
                <flux:input label="Situação" :value="$record->status->label()" readonly />
                <x-select search-source="advisors" wire:model.live="values.advisor_affiliation_id" :value="$values['advisor_affiliation_id']" label="Orientador" :options="$choices['advisors']" :error="$errors->first('advisor_affiliation_id')" searchable required />
                <flux:input wire:model="values.co_advisor_name" error:name="co_advisor_name" label="Co-orientador (opcional)" maxlength="255" />
                <flux:input wire:model="values.internship_sector" error:name="internship_sector" label="Setor ou área de atuação" maxlength="255" />
                <flux:input wire:model="values.planned_start_date" error:name="planned_start_date" label="Início planejado" type="date" :readonly="! $canEditAgreement" required />
                <flux:input label="Término previsto" :value="$hasProjectionChanges ? ($previewEndDate ? formatShort($previewEndDate) : 'Prévia pendente') : formatShort($record->projected_end_date)" description:trailing="Consulte o calendário para conferir a prévia. O término é atualizado ao salvar as alterações." readonly />
                <flux:input wire:model="values.rules.required_hours" error:name="rules.required_hours" label="Carga horária exigida (horas)" type="number" min="1" step="1" required />
            </div>
            <div class="mt-5 space-y-3">
                <flux:button type="button" variant="outline" icon="calendar-days" wire:click="previewCalendar" wire:loading.attr="disabled" wire:target="previewCalendar">Ver calendário do estágio</flux:button>
                <flux:error name="projected_end_date" />
                <x-accordion title="Regras deste estágio" :open="true">
                    <div class="space-y-6">
                        <flux:text>Estes valores pertencem apenas a este estágio. Alterações no cadastro do tipo não atualizam esta cópia, e os ajustes abaixo não alteram o cadastro do tipo.</flux:text>
                        <flux:error name="rules" />
                        <div class="grid items-start gap-5 md:grid-cols-3">
                            <flux:input wire:model="values.rules.max_daily_hours" error:name="rules.max_daily_hours" label="Limite diário (horas)" type="number" min="6" max="24" step="1" required />
                            <flux:input wire:model="values.rules.max_weekly_hours" error:name="rules.max_weekly_hours" label="Limite semanal (horas)" type="number" min="30" max="40" step="1" required />
                            <flux:input wire:model="values.rules.safety_margin_days" error:name="rules.safety_margin_days" label="Margem de segurança (dias corridos)" type="number" min="0" step="1" required />
                        </div>
                        <div class="space-y-4">
                            <div><flux:heading level="3">Pesos da avaliação</flux:heading><flux:text class="mt-1">A soma dos três pesos deve ser exatamente 10.</flux:text></div>
                            <div class="grid items-start gap-5 md:grid-cols-3">
                                <flux:input wire:model.live.debounce.400ms="values.rules.supervisor_evaluation_weight" error:name="rules.supervisor_evaluation_weight" label="Avaliação do supervisor" type="number" min="1" step="1" required />
                                <flux:input wire:model="values.rules.report_weight" error:name="rules.report_weight" label="Relatório" type="number" min="1" step="1" required />
                                <flux:input wire:model="values.rules.presentation_weight" error:name="rules.presentation_weight" label="Apresentação" type="number" min="1" step="1" required />
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div><flux:heading level="3">Conceitos da avaliação</flux:heading><flux:text class="mt-1">Valores com até uma casa decimal, em ordem estritamente decrescente. Ótimo acompanha o peso do supervisor.</flux:text></div>
                            <div class="grid items-start gap-5 sm:grid-cols-2 lg:grid-cols-5">
                                @foreach (\App\Enums\EvaluationConcept::cases() as $concept)
                                    <div wire:key="internship-concept-{{ $concept->value }}">
                                        @if ($concept->internshipTypeValueAttribute() === null)
                                            <flux:input :value="$typeRules['excellent']" :label="$concept->label()" readonly />
                                        @else
                                            @php($field = $concept->internshipTypeValueAttribute())
                                            <flux:input wire:model="values.rules.{{ $field }}" error:name="rules.{{ $field }}" :label="$concept->label()" type="text" inputmode="decimal" data-decimal-input required />
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="space-y-4">
                            <flux:heading level="3">Anos ou semestres permitidos</flux:heading>
                            <div class="grid items-start gap-5 sm:grid-cols-3">
                                <x-select wire:model="periodUnit" :value="$periodUnit" label="Tipo de período" :options="['year' => 'Ano', 'semester' => 'Semestre']" :error="$errors->first('periodUnit')" />
                                <flux:input wire:model="periodNumber" error:name="periodNumber" label="Número do ano ou semestre" type="number" min="1" max="99" step="1" wire:keydown.enter.prevent="addAcademicPeriod" />
                                <div class="self-end"><flux:button type="button" icon="plus" wire:click="addAcademicPeriod" wire:loading.attr="disabled">Adicionar período</flux:button></div>
                            </div>
                            <flux:error name="rules.allowed_academic_periods" />
                            <div class="flex flex-wrap gap-3">
                                @forelse ($values['rules']['allowed_academic_periods'] ?? [] as $index => $period)
                                    <div wire:key="internship-period-{{ $index }}-{{ $period }}" class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                                        <span class="text-sm">{{ $period }}</span>
                                        <flux:button type="button" size="xs" variant="ghost" icon="x-mark" :aria-label="'Remover '.$period" wire:click="removeAcademicPeriod({{ $index }})" />
                                        <flux:error name="rules.allowed_academic_periods.{{ $index }}" />
                                    </div>
                                @empty
                                    <flux:text size="sm">Adicione pelo menos um ano ou semestre permitido.</flux:text>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </x-accordion>
            </div>
            <div class="mt-5"><flux:textarea wire:model="values.activities" error:name="activities" label="Atividades" maxlength="20000" rows="4" required /></div>
        </section>
        <section class="border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="edit-party-heading">
            <flux:heading id="edit-party-heading" size="lg" level="2">Parte concedente</flux:heading>
            <flux:text class="mt-1">Os dados abaixo pertencem a este estágio. O cadastro da concedente permanece preservado.</flux:text>
            <div class="mt-5 grid items-start gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2"><x-select search-source="parties" wire:model.live="values.granting_party_id" :value="$values['granting_party_id']" label="Concedente" :options="$choices['parties']" :error="$errors->first('granting_party_id')" searchable required /></div>
                <flux:input label="CPF/CNPJ" :value="\App\Helpers\BrazilianDocumentHelper::formatCpfCnpj($partyDocument)" readonly />
                <flux:input wire:model="values.party.name" error:name="party.name" label="Nome ou razão social no estágio" maxlength="255" required />
                <flux:input wire:model="values.party.phone" error:name="party.phone" label="Telefone" type="tel" />
                <flux:input wire:model="values.party.email" error:name="party.email" label="E-mail" type="email" maxlength="254" />
                <flux:input wire:model="values.party.representative_name" error:name="party.representative_name" label="Representante legal" maxlength="255" />
                <flux:input wire:model="values.party.representative_role" error:name="party.representative_role" label="Cargo do representante" maxlength="255" />
                <flux:input wire:model="values.party.field_of_activity" error:name="party.field_of_activity" label="Área de atuação da concedente" maxlength="255" />
                <flux:input wire:model="values.party.professional_council" error:name="party.professional_council" label="Conselho profissional" maxlength="255" />
                <flux:input wire:model="values.party.council_registration_number" error:name="party.council_registration_number" label="Registro no conselho" maxlength="255" />
                <flux:input wire:model="values.party.credentialing_process_number" error:name="party.credentialing_process_number" label="Processo de credenciamento" maxlength="255" />
            </div>
        </section>
        <section class="border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="edit-workplace-heading">
            <flux:heading id="edit-workplace-heading" size="lg" level="2">Local de estágio</flux:heading>
            @if ($canEditAgreement)
                <x-address.form-fields :values="$values['address']" :selected-city-id="$selectedCityId" :campus-id="$record->course->campus_id" :selection-key="'internship-workplace-'.$citySelectionVersion" :city-message="$cityLookupMessage" :cep-lookup-changes="$this->cepLookupPreviewChanges()" />
            @else
                <div class="mt-5 grid items-start gap-5 sm:grid-cols-2">
                    <flux:input label="Município de execução" :value="$record->workplaceAddress->city->name.' / '.$record->workplaceAddress->city->state->value" description="O município histórico é preservado após a liberação." readonly />
                    <flux:input wire:model="values.address.zip_code" error:name="address.zip_code" label="CEP" maxlength="10" required />
                    <flux:input wire:model="values.address.street" error:name="address.street" label="Logradouro" maxlength="255" required />
                    <flux:input wire:model="values.address.number" error:name="address.number" label="Número" maxlength="255" required />
                    <flux:input wire:model="values.address.neighborhood" error:name="address.neighborhood" label="Bairro" maxlength="255" required />
                </div>
            @endif
        </section>
        <section class="border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="edit-supervisor-heading">
            <flux:heading id="edit-supervisor-heading" size="lg" level="2">Supervisor</flux:heading>
            <div class="mt-5 grid items-start gap-5 sm:grid-cols-2">
                <x-select search-source="supervisors" wire:model.live="values.supervisor_affiliation_id" :value="$values['supervisor_affiliation_id']" label="Supervisor" :options="$choices['supervisors']" :error="$errors->first('supervisor_affiliation_id')" searchable required />
                <x-select wire:model="values.supervisor_qualification_id" :value="$values['supervisor_qualification_id']" label="Qualificação" :options="$choices['qualifications']" :error="$errors->first('supervisor_qualification_id')" placeholder="Selecione uma qualificação" />
                <flux:input wire:model="values.supervisor.job_role" error:name="supervisor.job_role" label="Cargo ou função na concedente" maxlength="255" required />
                <flux:input wire:model="values.supervisor.phone" error:name="supervisor.phone" label="Telefone no estágio" type="tel" />
                <flux:input wire:model="values.supervisor.email" error:name="supervisor.email" label="E-mail no estágio" type="email" maxlength="254" />
            </div>
            @if ((int) $values['advisor_affiliation_id'] !== $record->advisor_affiliation_id || (int) $values['supervisor_affiliation_id'] !== $record->supervisor_affiliation_id)
                <div class="mt-5"><flux:textarea wire:model="values.change_reason" error:name="change_reason" label="Motivo da substituição de orientador ou supervisor" maxlength="1000" rows="2" required /></div>
            @endif
        </section>
        <section class="border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="edit-schedule-heading">
            <flux:heading id="edit-schedule-heading" size="lg" level="2">Jornada semanal</flux:heading>
            <flux:text class="mt-1">Limites deste estágio: {{ $typeRules['max_daily_hours'] ?? '—' }} h por dia e {{ $typeRules['max_weekly_hours'] ?? '—' }} h por semana. Margem de {{ $typeRules['safety_margin_days'] ?? '—' }} dias corridos.</flux:text>
            @if (! $canEditAgreement)<flux:text class="mt-2">A jornada formalizada é preservada. As pausas podem ser gerenciadas nos detalhes do estágio.</flux:text>@endif
            <div class="mt-5 grid items-start gap-5 sm:grid-cols-4 lg:grid-cols-7">
                @foreach (['sunday' => 'Domingo', 'monday' => 'Segunda', 'tuesday' => 'Terça', 'wednesday' => 'Quarta', 'thursday' => 'Quinta', 'friday' => 'Sexta', 'saturday' => 'Sábado'] as $day => $label)
                    <div wire:key="edit-hours-{{ $day }}"><flux:input wire:model="values.weekly_hours.{{ $day }}" error:name="weekly_hours.{{ $day }}" :label="$label" type="number" min="0" :max="$typeRules['max_daily_hours'] ?? 24" :readonly="! $canEditAgreement" required /></div>
                @endforeach
            </div>
            <flux:error name="weekly_hours" />
            <div class="mt-6 space-y-4">
                <flux:heading level="3">Pausas previstas na abertura</flux:heading>
                @foreach ($values['planned_pauses'] as $index => $pause)
                    <div wire:key="edit-planned-pause-{{ $index }}" class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                        <div class="grid items-start gap-5 sm:grid-cols-2">
                            <flux:input wire:model="values.planned_pauses.{{ $index }}.starts_at" error:name="planned_pauses.{{ $index }}.starts_at" type="date" label="Início da pausa" :readonly="! $canEditAgreement" required />
                            <flux:input wire:model="values.planned_pauses.{{ $index }}.ends_at" error:name="planned_pauses.{{ $index }}.ends_at" type="date" label="Fim da pausa" :readonly="! $canEditAgreement" required />
                            <div class="sm:col-span-2"><flux:textarea wire:model="values.planned_pauses.{{ $index }}.reason" error:name="planned_pauses.{{ $index }}.reason" label="Motivo" maxlength="1000" rows="2" :readonly="! $canEditAgreement" required /></div>
                        </div>
                        @if ($canEditAgreement)<flux:button type="button" variant="outline" icon="trash" wire:click="removePlannedPause({{ $index }})">Remover pausa prevista</flux:button>@endif
                    </div>
                @endforeach
                @if ($values['planned_pauses'] === [])<flux:text>Nenhuma pausa prevista na abertura.</flux:text>@endif
                <flux:error name="planned_pauses" />
                @if ($canEditAgreement)<flux:button type="button" variant="outline" icon="plus" wire:click="addPlannedPause" :disabled="count($values['planned_pauses']) >= InternshipPlannedPauses::MAX_PERIODS">Adicionar pausa prevista</flux:button>@endif
            </div>
        </section>
        <section class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="edit-overrides-heading">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading id="edit-overrides-heading" size="lg" level="2">Exceções do calendário</flux:heading>
                @can('manageCalendar', $record)<flux:button type="button" variant="outline" icon="plus" wire:click="addCalendarOverride" :disabled="count($values['calendar_overrides']) >= 3661">Adicionar exceção</flux:button>@endcan
            </div>
            <flux:text>Libere ou bloqueie uma data deste estágio. A liberação respeita a jornada e as pausas. Registre recessos e fechamentos como pausas. As exceções entram na prévia do calendário e são gravadas junto com Salvar alterações.</flux:text>
            <flux:error name="calendar_overrides" />
            @forelse ($values['calendar_overrides'] as $index => $override)
                <div wire:key="edit-override-{{ $override['id'] ?? 'new-'.$index }}" class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                    <div class="grid items-start gap-5 sm:grid-cols-2">
                        <flux:input wire:model="values.calendar_overrides.{{ $index }}.date" error:name="calendar_overrides.{{ $index }}.date" type="date" label="Data da exceção" :readonly="! $canManageCalendar" required />
                        <flux:field>
                            <flux:label>Expediente</flux:label>
                            <div class="flex min-h-10 items-center">
                                <flux:checkbox wire:model="values.calendar_overrides.{{ $index }}.is_working_day" error:name="calendar_overrides.{{ $index }}.is_working_day" label="Liberar expediente nesta data" :disabled="! $canManageCalendar" />
                            </div>
                        </flux:field>
                        <flux:text class="sm:col-span-2">Desmarcado: bloquear expediente. Marcado: liberar o feriado, mantendo a jornada e as pausas.</flux:text>
                        <div class="sm:col-span-2"><flux:textarea wire:model="values.calendar_overrides.{{ $index }}.reason" error:name="calendar_overrides.{{ $index }}.reason" label="Justificativa" maxlength="1000" rows="2" :readonly="! $canManageCalendar" required /></div>
                    </div>
                    <flux:error name="calendar_overrides.{{ $index }}.id" />
                    @if ($override['id'] === null)
                        @can('manageCalendar', $record)<flux:button type="button" variant="outline" icon="trash" wire:click="removeNewCalendarOverride({{ $index }})">Remover nova exceção</flux:button>@endcan
                    @endif
                </div>
            @empty
                <flux:text>Nenhuma exceção registrada.</flux:text>
            @endforelse
        </section>
        <section class="border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="edit-remuneration-heading">
            <flux:heading id="edit-remuneration-heading" size="lg" level="2">Remuneração</flux:heading>
            <div class="mt-5"><flux:switch align="left" wire:model.live="values.is_remunerated" error:name="is_remunerated" label="Estágio remunerado" /></div>
            @if ($values['is_remunerated'])
                <div class="mt-5 grid items-start gap-5 sm:grid-cols-2">
                    <flux:input wire:model="values.grant_value" error:name="grant_value" label="Bolsa mensal (R$)" type="text" inputmode="decimal" data-decimal-input required />
                    <flux:input wire:model="values.transportation_allowance" error:name="transportation_allowance" label="Auxílio transporte (R$)" type="text" inputmode="decimal" data-decimal-input />
                </div>
            @endif
        </section>
        <section class="border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="edit-observations-heading">
            <flux:heading id="edit-observations-heading" size="lg" level="2">Observações</flux:heading>
            <div class="mt-5"><flux:textarea wire:model="values.observations" error:name="observations" label="Observações do estágio" maxlength="10000" rows="4" /></div>
        </section>
        <div class="flex flex-wrap justify-end gap-3">
            <flux:button variant="outline" :href="route('internships.show', $record)" wire:navigate>Cancelar</flux:button>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Salvar alterações</flux:button>
        </div>
    </form>
    <x-internship-calendar-preview wire:model.self="showCalendar" :result="$calendarResult" :editing="! $calendarIsSaved" :saved="$calendarIsSaved" />
</div>
