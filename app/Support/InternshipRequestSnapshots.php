<?php

namespace App\Support;

use App\Actions\CopyAddress;
use App\Models\Affiliation;
use App\Models\GrantingParty;
use App\Models\InternshipRequest;
use App\Models\InternshipType;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class InternshipRequestSnapshots
{
    public function __construct(private CopyAddress $copyAddress) {}

    public function student(InternshipRequest $request, Affiliation $owner): void
    {
        $user = User::query()->lockForUpdate()->findOrFail($owner->user_id);
        $profile = $user->personalData()->lockForUpdate()->first();
        $data = ['name' => $user->name, 'cpf' => $user->cpf, 'email' => $owner->email,
            'registration_number' => $owner->registration_number, 'student_year_semester' => $request->student_year_semester,
            'rg' => $profile?->rg, 'rg_issuer' => $profile?->rg_issuer, 'rg_issue_date' => $profile?->rg_issue_date?->toDateString(),
            'birth_date' => $profile?->birth_date?->toDateString(), 'phone' => $profile?->phone, 'address_id' => $profile?->address_id];
        Validator::make($data, array_fill_keys(array_keys($data), ['required']), attributes: [
            'name' => 'nome', 'cpf' => 'CPF', 'email' => 'e-mail', 'registration_number' => 'matrícula',
            'student_year_semester' => 'ano/semestre', 'rg' => 'RG', 'rg_issuer' => 'órgão emissor do RG',
            'rg_issue_date' => 'data de emissão do RG', 'birth_date' => 'data de nascimento', 'phone' => 'telefone', 'address_id' => 'endereço nos dados pessoais',
        ])->validate();
        unset($data['address_id']);
        $request->student_snapshot = $data + $request->only(['legal_capacity_declaration', 'legal_guardian_name', 'legal_guardian_cpf', 'legal_guardian_kinship', 'legal_guardian_email']);
        $request->student_address_id = $this->copyAddress->handle($profile->address()->firstOrFail())->id;
    }

    /** @return array<string, mixed> */
    public function type(InternshipType $type): array
    {
        return ['id' => $type->id, 'name' => $type->name, 'rules' => $type->only([
            'allowed_academic_periods', 'required_hours', 'supervisor_evaluation_weight', 'report_weight', 'presentation_weight',
            'very_good_value', 'good_value', 'satisfactory_value', 'unsatisfactory_value',
            'max_daily_hours', 'max_weekly_hours', 'safety_margin_days',
        ]) + ['excellent' => $type->supervisor_evaluation_weight, 'unsatisfactory' => $type->unsatisfactory_value]];
    }

    /** @return array<string, mixed> */
    public function party(GrantingParty $party): array
    {
        return $party->only(['document_type', 'document_number', 'name', 'representative_name', 'representative_role',
            'phone', 'email', 'field_of_activity', 'professional_council', 'council_registration_number', 'credentialing_process_number']);
    }

    /** @param array<string, mixed> $professional
     * @return array<string, mixed>
     */
    public function supervisor(Affiliation $supervisor, array $professional): array
    {
        $user = $supervisor->user()->firstOrFail();
        $profile = $user->personalData()->first();

        return ['name' => $user->name, 'email' => $supervisor->email, 'phone' => $profile?->phone] + $professional;
    }
}
