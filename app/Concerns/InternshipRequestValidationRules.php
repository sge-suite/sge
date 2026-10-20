<?php

namespace App\Concerns;

use App\Enums\BrazilianState;
use App\Enums\EducationLevel;
use App\Enums\LegalCapacityDeclaration;
use App\Enums\PartyDocumentType;
use App\Helpers\BrazilianAddressHelper;
use App\Helpers\BrazilianContactHelper;
use App\Support\InternshipEndDateCalculator;
use App\Support\InternshipPlannedPauses;
use App\Support\InternshipRequestPersonalData;
use Illuminate\Validation\Rule;
use LaravelLegends\PtBrValidator\Rules\FormatoCep;

trait InternshipRequestValidationRules
{
    /** @return array<string, string> */
    public function internshipFieldLabels(): array
    {
        $labels = ['internship_type_id' => 'tipo de estágio', 'student_year_semester' => 'ano ou semestre', 'advisor_affiliation_id' => 'orientador', 'co_advisor_name' => 'co-orientador',
            'granting_party_change_note' => 'alterações da concedente', 'granting_party_id' => 'concedente', 'supervisor_affiliation_id' => 'supervisor',
            'granting_party_mode' => 'opção de concedente', 'supervisor_mode' => 'opção de supervisor',
            'legal_capacity_declaration' => 'capacidade civil', 'legal_guardian_name' => 'nome do responsável',
            'legal_guardian_cpf' => 'CPF do responsável', 'legal_guardian_kinship' => 'parentesco do responsável', 'legal_guardian_email' => 'e-mail do responsável',
            'activities' => 'atividades previstas', 'internship_sector' => 'setor ou área', 'observations' => 'observações',
            'weekly_hours' => 'jornada semanal', 'planned_start_date' => 'data de início', 'projected_end_date' => 'previsão de término',
            'is_remunerated' => 'opção de remuneração', 'grant_value' => 'bolsa', 'transportation_allowance' => 'auxílio-transporte',
            'name' => 'nome', 'cpf' => 'CPF', 'phone' => 'telefone', 'email' => 'e-mail', 'document_type' => 'tipo de documento', 'document_number' => 'CPF ou CNPJ',
            'street' => 'logradouro', 'number' => 'número', 'neighborhood' => 'bairro', 'city_id' => 'cidade', 'city' => 'cidade', 'uf' => 'UF', 'zip_code' => 'CEP',
            'representative_name' => 'representante legal', 'representative_role' => 'cargo do representante', 'field_of_activity' => 'área de atuação',
            'professional_council' => 'conselho profissional', 'council_registration_number' => 'registro no conselho', 'credentialing_process_number' => 'processo de credenciamento',
            'job_role' => 'cargo ou função do supervisor', 'has_academic_background' => 'qualificação do supervisor', 'training_course' => 'curso de formação',
            'education_level' => 'nível de formação', 'experience_time' => 'tempo de experiência'];
        foreach (['granting_party_proposal', 'supervisor_proposal', 'supervisor_professional'] as $prefix) {
            foreach ($labels as $field => $label) {
                if (! str_contains($field, '.')) {
                    $labels[$prefix.'.'.$field] = $label;
                }
            }
        }
        foreach (['sunday' => 'domingo', 'monday' => 'segunda-feira', 'tuesday' => 'terça-feira', 'wednesday' => 'quarta-feira', 'thursday' => 'quinta-feira', 'friday' => 'sexta-feira', 'saturday' => 'sábado'] as $day => $label) {
            $labels['weekly_hours.'.$day] = 'horas de '.$label;
        }

        return $labels + app(InternshipRequestPersonalData::class)->labels() + app(InternshipPlannedPauses::class)->labels();
    }

    /** @return array<string, list<string>> */
    public static function internshipSections(): array
    {
        return [
            'personal_data' => ['personal_data'],
            'internship' => ['internship_type_id', 'student_year_semester', 'advisor_affiliation_id', 'co_advisor_name'],
            'granting_party' => ['granting_party_id', 'granting_party_mode', 'granting_party_proposal', 'granting_party_change_note'],
            'supervisor' => ['supervisor_affiliation_id', 'supervisor_mode', 'supervisor_proposal', 'supervisor_professional'],
            'activities' => ['activities', 'internship_sector', 'observations'],
            'schedule' => ['weekly_hours', 'planned_pauses'],
            'planned_start_date' => ['planned_start_date'],
            'legal_capacity' => ['legal_capacity_declaration', 'legal_guardian_name', 'legal_guardian_cpf', 'legal_guardian_kinship', 'legal_guardian_email'],
            'remuneration' => ['is_remunerated', 'grant_value', 'transportation_allowance'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public function internshipInputRules(): array
    {
        $rules = [
            'internship_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'advisor_affiliation_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'co_advisor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'student_year_semester' => ['sometimes', 'nullable', 'string', 'max:255'],
            'granting_party_mode' => ['sometimes', Rule::in(['existing', 'pending'])],
            'granting_party_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'supervisor_mode' => ['sometimes', Rule::in(['existing', 'pending'])],
            'supervisor_affiliation_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'legal_capacity_declaration' => ['sometimes', 'nullable', Rule::enum(LegalCapacityDeclaration::class)],
            'legal_guardian_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'legal_guardian_cpf' => ['sometimes', 'nullable', 'cpf'],
            'legal_guardian_kinship' => ['sometimes', 'nullable', 'string', 'max:80'],
            'legal_guardian_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'activities' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'internship_sector' => ['sometimes', 'nullable', 'string', 'max:255'],
            'observations' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'weekly_hours' => ['sometimes', 'nullable', 'array:'.implode(',', InternshipEndDateCalculator::DAYS)],
            'weekly_hours.*' => ['nullable', 'integer', 'min:0', 'max:24'],
            'planned_start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'is_remunerated' => ['sometimes', 'nullable', 'boolean'],
            'grant_value' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'transportation_allowance' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ];
        $partyFields = ['document_type', 'document_number', 'name', 'street', 'number', 'neighborhood', 'city_id', 'uf', 'zip_code',
            'representative_name', 'representative_role', 'phone', 'email', 'field_of_activity', 'professional_council', 'council_registration_number', 'credentialing_process_number'];
        $supervisorFields = ['name', 'cpf', 'phone', 'email', 'job_role', 'has_academic_background', 'training_course', 'education_level', 'experience_time'];
        $professionalFields = ['job_role'];
        foreach (['granting_party_proposal' => $partyFields, 'supervisor_proposal' => $supervisorFields, 'supervisor_professional' => $professionalFields] as $prefix => $fields) {
            $rules[$prefix] = ['sometimes', 'array:'.implode(',', $fields)];
            foreach ($fields as $field) {
                $rules[$prefix.'.'.$field] = ['sometimes', 'nullable', 'string', 'max:255'];
            }
        }
        $rules['granting_party_change_note'] = ['sometimes', 'nullable', 'string', 'max:5000'];
        $rules['granting_party_proposal.document_type'] = ['sometimes', 'nullable', Rule::enum(PartyDocumentType::class)];
        $rules['granting_party_proposal.document_number'] = ['sometimes', 'nullable', 'cpf_ou_cnpj'];
        $rules['granting_party_proposal.uf'] = ['sometimes', 'nullable', Rule::enum(BrazilianState::class)];
        $rules['granting_party_proposal.city_id'] = ['sometimes', 'nullable', 'integer', 'min:1'];
        $rules['granting_party_proposal.zip_code'] = ['sometimes', 'nullable', new FormatoCep];
        $rules['supervisor_proposal.cpf'] = ['sometimes', 'nullable', 'cpf'];
        $rules['supervisor_proposal.has_academic_background'] = ['sometimes', 'nullable', 'boolean'];
        $rules['supervisor_proposal.education_level'] = ['sometimes', 'nullable', Rule::enum(EducationLevel::class)];
        foreach (['granting_party_proposal', 'supervisor_proposal'] as $prefix) {
            $rules[$prefix.'.email'] = ['sometimes', 'nullable', 'email', 'max:255'];
            $rules[$prefix.'.phone'] = ['sometimes', 'nullable', 'celular_com_ddd'];
        }

        return $rules + app(InternshipRequestPersonalData::class)->rules() + app(InternshipPlannedPauses::class)->rules();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalizeInternshipInput(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->normalizeInternshipInput($value);
            } elseif (is_string($value)) {
                $data[$key] = trim($value) === '' ? null : trim($value);
                if ($key === 'phone' && ctype_digit((string) $data[$key]) && in_array(strlen((string) $data[$key]), [10, 11], true)) {
                    $data[$key] = BrazilianContactHelper::formatPhone($data[$key]);
                }
                if ($key === 'zip_code') {
                    $data[$key] = BrazilianAddressHelper::cepForValidation($data[$key]);
                }
            }
        }

        return $data;
    }
}
