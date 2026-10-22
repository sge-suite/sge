<?php

namespace App\Actions;

use App\Concerns\AddressValidationRules;
use App\Concerns\InternshipRequestValidationRules;
use App\Enums\AffiliationType;
use App\Helpers\BrazilianAddressHelper;
use App\Helpers\DigitsHelper;
use App\Models\Address;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\GrantingParty;
use App\Models\Internship;
use App\Models\InternshipType;
use App\Models\SupervisorQualification;
use App\Models\User;
use App\Support\InternshipCalendarOverrides;
use App\Support\InternshipEndDateCalculator;
use App\Support\InternshipPlannedPauses;
use App\Support\InternshipRequestSnapshots;
use App\Support\StudentInternshipContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;
use Spatie\Activitylog\Support\CauserResolver;

class UpdateInternshipData
{
    use AddressValidationRules;
    use InternshipRequestValidationRules;

    public const PARTY_FIELDS = ['name', 'phone', 'email', 'representative_name', 'representative_role', 'field_of_activity', 'professional_council', 'council_registration_number', 'credentialing_process_number'];

    public function __construct(private StudentInternshipContext $context, private CauserResolver $causerResolver, private InternshipRequestSnapshots $snapshots, private ProjectInternshipEndDate $project, private InternshipPlannedPauses $plannedPauses, private InternshipCalendarOverrides $overrides) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, Internship $target, int $expectedAffiliationId, string $expectedUpdatedAt, array $data, bool $recalculate = false): Internship
    {
        Gate::forUser($actor)->authorize('update', $target);

        return DB::transaction(function () use ($actor, $target, $expectedAffiliationId, $expectedUpdatedAt, $data, $recalculate): Internship {
            abort_unless($this->context->selected($actor)?->id === $expectedAffiliationId, 403, 'O vínculo selecionado mudou. Reabra o estágio.');
            $affiliation = Affiliation::query()->lockForUpdate()->findOrFail($expectedAffiliationId);
            abort_unless($affiliation->user_id === $actor->id && $affiliation->deactivated_at === null && $affiliation->type === AffiliationType::InternshipOffice, 403);
            $campus = Campus::query()->lockForUpdate()->findOrFail($affiliation->campus_id);
            $campus->assertWritable();
            $internship = Internship::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('update', $internship);
            if ($internship->getRawOriginal('updated_at') !== $expectedUpdatedAt) {
                throw ValidationException::withMessages(['internship' => 'O estágio foi alterado desde a abertura do formulário. Recarregue a página antes de salvar.']);
            }

            return $this->causerResolver->withCauser($affiliation, fn (): Internship => $this->save($actor, $internship, $campus, $data, $recalculate));
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    private function save(User $actor, Internship $internship, Campus $campus, array $data, bool $recalculate): Internship
    {
        $data = $this->normalizeInternshipInput($data);
        foreach (['grant_value', 'transportation_allowance'] as $field) {
            if (is_string($data[$field] ?? null)) {
                $data[$field] = str_replace(',', '.', $data[$field]);
            }
        }
        if (is_array($data['address'] ?? null)) {
            $data['address']['zip_code'] = BrazilianAddressHelper::cepForValidation($data['address']['zip_code'] ?? null);
        }
        $rules = [
            'internship_type_id' => ['required', 'integer', 'min:1'],
            'rules' => ['sometimes', 'required', 'array'],
            'calendar_overrides' => ['sometimes', 'array'],
            'advisor_affiliation_id' => ['required', 'integer', 'min:1'],
            'co_advisor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'supervisor_affiliation_id' => ['required', 'integer', 'min:1'],
            'supervisor_qualification_id' => ['nullable', 'integer', 'min:1'],
            'granting_party_id' => ['required', 'integer', 'min:1'],
            'student_year_semester' => ['required', 'string', 'max:255'],
            'activities' => ['required', 'string', 'max:20000'], 'internship_sector' => ['nullable', 'string', 'max:255'],
            'observations' => ['nullable', 'string', 'max:10000'],
            'planned_start_date' => ['required', 'date_format:Y-m-d'],
            'weekly_hours' => ['required', 'array:'.implode(',', InternshipEndDateCalculator::DAYS), 'size:7'],
            'weekly_hours.*' => ['required', 'integer', 'min:0', 'max:24'],
            'is_remunerated' => ['required', 'boolean'],
            'grant_value' => [Rule::requiredIf((bool) ($data['is_remunerated'] ?? false)), 'nullable', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'transportation_allowance' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'address' => ['required', 'array:city_id,street,number,neighborhood,zip_code'],
            'party' => ['required', 'array:'.implode(',', self::PARTY_FIELDS)],
            'supervisor' => ['required', 'array:job_role,phone,email'],
            'supervisor.job_role' => ['required', 'string', 'max:255'],
            'supervisor.phone' => ['nullable', 'string', 'celular_com_ddd'], 'supervisor.email' => ['nullable', 'email', 'max:254'],
            'change_reason' => ['nullable', 'string', 'max:1000'],
        ] + $this->plannedPauses->rules(complete: true);
        foreach ($this->addressRules() as $field => $fieldRules) {
            $rules['address.'.$field] = $fieldRules;
        }
        foreach (self::PARTY_FIELDS as $field) {
            $rules['party.'.$field] = [$field === 'name' ? 'required' : 'nullable', 'string', 'max:255'];
        }
        $rules['party.phone'] = ['nullable', 'string', 'celular_com_ddd'];
        $rules['party.email'] = ['nullable', 'email', 'max:254'];
        foreach (array_diff(array_keys($data), array_keys($rules)) as $field) {
            $rules[$field] = ['prohibited'];
        }
        $values = Validator::make($data, $rules, attributes: $this->internshipFieldLabels() + [
            'address.city_id' => 'cidade', 'address.street' => 'logradouro', 'address.number' => 'número',
            'address.neighborhood' => 'bairro', 'address.zip_code' => 'CEP', 'party.name' => 'nome da concedente',
            'party.email' => 'e-mail da concedente', 'party.phone' => 'telefone da concedente',
            'supervisor.job_role' => 'cargo do supervisor', 'supervisor.phone' => 'telefone do supervisor', 'supervisor.email' => 'e-mail do supervisor',
        ])->validate();
        $values['weekly_hours'] = array_map(fn (mixed $hours): int => (int) $hours, $values['weekly_hours']);
        $address = $internship->workplaceAddress()->lockForUpdate()->firstOrFail();
        $canEditAgreement = Gate::forUser($actor)->allows('updateAgreement', $internship);
        if ($recalculate && ! $canEditAgreement) {
            Gate::forUser($actor)->authorize('manageCalendar', $internship);
        }
        if (! $canEditAgreement) {
            foreach (['internship_type_id' => $internship->internship_type_id, 'planned_start_date' => $internship->planned_start_date->toDateString(),
                'weekly_hours' => $internship->weekly_hours, 'planned_pauses' => $internship->planned_pauses ?? []] as $field => $original) {
                $candidate = $values[$field] ?? [];
                if (($field === 'internship_type_id' ? (int) $candidate : $candidate) != $original) {
                    throw ValidationException::withMessages([$field => 'Este dado já foi formalizado e não pode ser reescrito pela edição do estágio.']);
                }
            }
            if ((int) $values['address']['city_id'] !== $address->city_id) {
                throw ValidationException::withMessages(['address.city_id' => 'O município histórico de execução não pode ser alterado depois da liberação.']);
            }
        }

        $type = InternshipType::query()->where('course_id', $internship->course_id)
            ->when((int) $values['internship_type_id'] !== $internship->internship_type_id, fn ($query) => $query->whereNull('deactivated_at'))
            ->whereKey((int) $values['internship_type_id'])->lockForUpdate()->first();
        if ($type === null) {
            throw ValidationException::withMessages(['internship_type_id' => 'Selecione um tipo disponível do curso do discente.']);
        }
        $participants = Affiliation::query()->whereIn('id', [$values['advisor_affiliation_id'], $values['supervisor_affiliation_id']])
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach (['advisor_affiliation_id' => AffiliationType::Advisor, 'supervisor_affiliation_id' => AffiliationType::Supervisor] as $field => $requiredType) {
            $participant = $participants->get((int) $values[$field]);
            if ($participant === null || $participant->type !== $requiredType || $participant->campus_id !== $campus->id
                || ($participant->id !== $internship->{$field} && $participant->deactivated_at !== null)) {
                throw ValidationException::withMessages([$field => 'Selecione um vínculo disponível deste campus.']);
            }
        }
        $party = GrantingParty::query()->where('campus_id', $campus->id)
            ->when((int) $values['granting_party_id'] !== $internship->granting_party_id, fn ($query) => $query->whereNull('deactivated_at'))
            ->whereKey((int) $values['granting_party_id'])->lockForUpdate()->first();
        if ($party === null) {
            throw ValidationException::withMessages(['granting_party_id' => 'Selecione uma concedente disponível deste campus.']);
        }
        $replacesParticipant = (int) $values['advisor_affiliation_id'] !== $internship->advisor_affiliation_id
            || (int) $values['supervisor_affiliation_id'] !== $internship->supervisor_affiliation_id;
        if ($replacesParticipant && blank($values['change_reason'] ?? null)) {
            throw ValidationException::withMessages(['change_reason' => 'Informe o motivo da substituição de orientador ou supervisor.']);
        }
        $supervisorSnapshot = $internship->supervisor_snapshot;
        if ((int) $values['supervisor_affiliation_id'] !== $internship->supervisor_affiliation_id
            || ($values['supervisor_qualification_id'] ?? null) != ($supervisorSnapshot['source_qualification_id'] ?? null)) {
            $supervisor = $participants->get((int) $values['supervisor_affiliation_id']);
            $qualification = SupervisorQualification::query()->where('user_id', $supervisor->user_id)
                ->whereKey((int) ($values['supervisor_qualification_id'] ?? 0))->lockForUpdate()->first();
            if ($qualification === null) {
                throw ValidationException::withMessages(['supervisor_qualification_id' => 'Selecione uma qualificação cadastrada do supervisor.']);
            }
            $supervisorSnapshot = $this->snapshots->supervisor($supervisor, $qualification->snapshot());
        }
        $oldSupervisorSnapshot = $internship->supervisor_snapshot;
        $internship->supervisor_snapshot = array_replace($supervisorSnapshot, $values['supervisor']);
        $partySnapshot = (int) $values['granting_party_id'] === $internship->granting_party_id ? $internship->granting_party_snapshot : $this->snapshots->party($party);
        $internship->granting_party_snapshot = array_replace($partySnapshot, $values['party']);
        if ((int) $values['internship_type_id'] !== $internship->internship_type_id) {
            $internship->internship_type_snapshot = $this->snapshots->type($type);
        }
        if (isset($values['rules'])) {
            $internship->internship_type_snapshot = $this->snapshots->withTypeRules($internship->internship_type_snapshot, $values['rules']);
        }
        $periods = $internship->internship_type_snapshot['rules']['allowed_academic_periods'] ?? [];
        if (! in_array($values['student_year_semester'], $periods, true)
            && ((int) $values['internship_type_id'] !== $internship->internship_type_id || $values['student_year_semester'] !== ($internship->student_snapshot['student_year_semester'] ?? null)
                || $periods !== ($internship->getOriginal('internship_type_snapshot')['rules']['allowed_academic_periods'] ?? []))) {
            throw ValidationException::withMessages(['student_year_semester' => 'Selecione um ano ou semestre permitido pelo tipo de estágio.']);
        }
        $oldAcademicPeriod = $internship->student_snapshot['student_year_semester'] ?? null;
        $internship->student_snapshot = array_replace($internship->student_snapshot, ['student_year_semester' => $values['student_year_semester']]);
        $this->plannedPauses->validate($values['planned_pauses'] ?? [], $values['planned_start_date'], complete: true);
        if (($values['planned_pauses'] ?? []) === [] && $internship->planned_pauses === null) {
            $values['planned_pauses'] = null;
        }
        $calendarChanged = (int) $values['address']['city_id'] !== $address->city_id;
        $addressData = $values['address'];
        $addressData['city_id'] = (int) $addressData['city_id'];
        $addressData['zip_code'] = DigitsHelper::only($addressData['zip_code']);
        $replacement = new Address($addressData);
        if ($address->only(array_keys($addressData)) !== $replacement->only(array_keys($addressData))) {
            $replacement->save();
            $internship->workplace_address_id = $replacement->id;
        }
        $internship->fill(Arr::only($values, ['internship_type_id', 'advisor_affiliation_id', 'co_advisor_name', 'supervisor_affiliation_id', 'granting_party_id',
            'activities', 'internship_sector', 'observations', 'planned_start_date', 'weekly_hours', 'planned_pauses',
            'is_remunerated', 'grant_value', 'transportation_allowance']));
        if (! $internship->is_remunerated) {
            $internship->grant_value = null;
            $internship->transportation_allowance = null;
        }
        $overridesChanged = false;
        if (array_key_exists('calendar_overrides', $values)) {
            $overrides = $this->overrides->validate($internship, $values['calendar_overrides']);
            if ($overrides !== $this->overrides->values($internship)) {
                Gate::forUser($actor)->authorize('manageCalendar', $internship);
            }
            $overridesChanged = $this->overrides->sync($internship, $overrides);
        }
        if ($recalculate || $calendarChanged || $overridesChanged || $internship->isDirty(['internship_type_snapshot', 'planned_start_date', 'weekly_hours', 'planned_pauses'])) {
            $internship->endDateCalculation = $this->project->details($internship);
            $internship->projected_end_date = $internship->endDateCalculation['projected_end_date'];
        }
        if ($overridesChanged) {
            $internship->setUpdatedAt($internship->freshTimestamp());
        }
        $internship->managementChangeReason = $replacesParticipant ? $values['change_reason'] : null;
        $internship->save();
        if ($oldAcademicPeriod !== $values['student_year_semester']) {
            activity()->performedOn($internship)->event(ActivityEvent::Updated)
                ->withChanges(['old' => ['student_year_semester' => $oldAcademicPeriod], 'attributes' => ['student_year_semester' => $values['student_year_semester']]])->log('updated');
        }
        if ($oldSupervisorSnapshot !== $internship->supervisor_snapshot) {
            activity()->performedOn($internship)->event(ActivityEvent::Updated)
                ->withProperties(['changed_sections' => ['supervisor'], 'management_change_reason' => $values['change_reason'] ?? null])->log('Dados do supervisor atualizados');
        }

        return $internship;
    }
}
