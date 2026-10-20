<?php

namespace App\Support;

use App\Enums\EducationLevel;
use App\Enums\LegalCapacityDeclaration;
use App\Enums\PartyDocumentType;
use App\Helpers\BrazilianAddressHelper;
use App\Models\Address;
use App\Models\InternshipRequest;
use BackedEnum;

class InternshipRequestReceipt
{
    /** @return list<array{title: string, fields: array<string, string>}> */
    public function sections(InternshipRequest $request): array
    {
        $request->load(['affiliation.campus', 'course', 'advisorAffiliation.user', 'studentAddress.city', 'workplaceAddress.city', 'supervisorRegistrationRequest']);
        $student = $request->student_snapshot ?? [];
        $party = $request->granting_party_snapshot ?? [];
        $supervisor = $request->supervisor_snapshot ?? [];
        $type = $request->internship_type_snapshot ?? [];
        $sections = [
            ['title' => 'Registro do envio', 'fields' => ['Solicitação' => (string) $request->id,
                'Enviada em' => formatDateTime($request->terms_accepted_at), 'Situação' => $request->status->label(),
                'Campus' => $this->value($request->affiliation->campus?->name), 'Ciência das normas e dos dados' => 'Confirmada neste envio']],
            ['title' => 'Dados do Discente', 'fields' => [
                ...$this->fields($student, ['name' => 'Nome completo', 'cpf' => 'CPF', 'email' => 'E-mail do vínculo no formulário', 'registration_number' => 'Matrícula',
                    'student_year_semester' => 'Ano/semestre', 'rg' => 'RG', 'rg_issuer' => 'Órgão emissor do RG', 'phone' => 'Telefone']),
                'Emissão do RG' => formatShort($student['rg_issue_date'] ?? null), 'Nascimento' => formatShort($student['birth_date'] ?? null)]],
            ['title' => 'Endereço do Discente', 'fields' => $this->address($request->studentAddress)],
            ['title' => 'Curso e estágio', 'fields' => ['Curso' => $this->value($request->course?->name),
                'Tipo de estágio' => $this->value($type['name'] ?? null), 'Orientador' => $this->value($request->advisorAffiliation?->user->name),
                'Co-orientador' => $this->value($request->co_advisor_name),
                'E-mail do vínculo do orientador' => $this->value($request->advisorAffiliation?->email),
                ...$this->fields($type['rules'] ?? [], ['required_hours' => 'Carga horária obrigatória (horas)', 'max_daily_hours' => 'Limite diário (horas)',
                    'max_weekly_hours' => 'Limite semanal (horas)', 'safety_margin_days' => 'Margem de segurança (dias)',
                    'supervisor_evaluation_weight' => 'Peso da avaliação do supervisor', 'report_weight' => 'Peso do relatório', 'presentation_weight' => 'Peso da apresentação',
                    'excellent' => 'Conceito ótimo', 'very_good_value' => 'Conceito muito bom', 'good_value' => 'Conceito bom',
                    'satisfactory_value' => 'Conceito satisfatório', 'unsatisfactory_value' => 'Conceito insatisfatório'])]],
            ['title' => 'Parte concedente', 'fields' => [
                'Indicação' => $request->granting_party_registration_request_id === null ? 'Cadastro existente' : 'Pedido de cadastro',
                'Tipo de documento' => PartyDocumentType::tryFrom($this->value($party['document_type'] ?? null))?->label() ?? 'Não informado',
                ...$this->fields($party, ['document_number' => 'CPF/CNPJ', 'name' => 'Nome / razão social', 'representative_name' => 'Representante',
                    'representative_role' => 'Cargo do representante', 'phone' => 'Telefone', 'email' => 'E-mail', 'field_of_activity' => 'Área de atuação',
                    'professional_council' => 'Conselho profissional', 'council_registration_number' => 'Registro no conselho',
                    'credentialing_process_number' => 'Processo de credenciamento', 'reported_changes' => 'Alterações cadastrais informadas'])]],
            ['title' => 'Endereço da concedente / local do estágio', 'fields' => $request->workplaceAddress !== null
                ? $this->address($request->workplaceAddress) : $this->fields($party, ['city' => 'Cidade', 'uf' => 'UF'])],
            ['title' => 'Supervisor', 'fields' => [
                'Indicação' => $request->supervisor_registration_request_id === null ? 'Cadastro existente' : 'Pedido de cadastro',
                ...$this->fields($supervisor, ['name' => 'Nome completo', 'phone' => 'Telefone', 'email' => 'E-mail', 'job_role' => 'Cargo/função na concedente'])]],
        ];
        if ($request->supervisorRegistrationRequest !== null) {
            $fields = ['CPF' => $this->value($request->supervisorRegistrationRequest->cpf)];
            if (($supervisor['has_academic_background'] ?? false) === true) {
                $fields += ['Qualificação' => 'Formação na área', 'Curso de formação' => $this->value($supervisor['training_course'] ?? null),
                    'Nível de formação' => EducationLevel::tryFrom($this->value($supervisor['education_level'] ?? null))?->label() ?? 'Não informado'];
            } else {
                $fields += ['Qualificação' => 'Experiência profissional', 'Tempo de experiência' => $this->value($supervisor['experience_time'] ?? null)];
            }
            $sections[] = ['title' => 'Dados do pedido de supervisor', 'fields' => $fields];
        }
        $capacity = ['Capacidade civil' => $request->legal_capacity_declaration?->label() ?? 'Não informada'];
        if ($request->legal_capacity_declaration === LegalCapacityDeclaration::Minor) {
            $capacity += $this->fields($student, ['legal_guardian_name' => 'Nome do responsável', 'legal_guardian_cpf' => 'CPF do responsável',
                'legal_guardian_kinship' => 'Parentesco', 'legal_guardian_email' => 'E-mail do responsável']);
        } elseif ($request->legal_capacity_declaration === LegalCapacityDeclaration::EmancipatedMinor) {
            $capacity['Comprovante de emancipação'] = 'Enviado pelo SGE para análise';
        }
        $sections[] = ['title' => 'Capacidade civil', 'fields' => $capacity];
        $sections[] = ['title' => 'Atividades e datas', 'fields' => ['Setor/área' => $this->value($request->internship_sector),
            'Atividades previstas' => $this->value($request->activities), 'Início previsto' => formatShort($request->planned_start_date),
            'Término previsto' => formatShort($request->projected_end_date)]];
        $hours = [];
        foreach (['sunday' => 'Domingo', 'monday' => 'Segunda-feira', 'tuesday' => 'Terça-feira', 'wednesday' => 'Quarta-feira',
            'thursday' => 'Quinta-feira', 'friday' => 'Sexta-feira', 'saturday' => 'Sábado'] as $day => $label) {
            $hours[$label] = ($request->weekly_hours[$day] ?? 0).' h';
        }
        $hours['Total semanal'] = array_sum($request->weekly_hours ?? []).' h';
        $sections[] = ['title' => 'Jornada semanal', 'fields' => $hours];
        $pauses = [];
        foreach (array_values($request->planned_pauses ?? []) as $index => $pause) {
            $pauses['Período '.($index + 1)] = formatShort($pause['starts_at']).' a '.formatShort($pause['ends_at']).' — '.$pause['reason'];
        }
        $sections[] = ['title' => 'Pausas previstas', 'fields' => $pauses ?: ['Períodos' => 'Nenhuma pausa informada']];
        $sections[] = ['title' => 'Remuneração e observações', 'fields' => ['Remunerado' => $request->is_remunerated ? 'Sim' : 'Não',
            'Bolsa mensal' => $request->grant_value === null ? 'Não informada' : formatCurrency((float) $request->grant_value),
            'Auxílio-transporte' => $request->transportation_allowance === null ? 'Não informado' : formatCurrency((float) $request->transportation_allowance),
            'Observações' => $this->value($request->observations)]];

        return $sections;
    }

    /** @return array<string, string> */
    private function address(?Address $address): array
    {
        return ['Logradouro' => $this->value($address?->street), 'Número' => $this->value($address?->number),
            'Bairro' => $this->value($address?->neighborhood), 'CEP' => BrazilianAddressHelper::formatCep($address?->zip_code),
            'Cidade' => $this->value($address?->city?->name), 'UF' => $this->value($address?->city?->state)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $labels
     * @return array<string, string>
     */
    private function fields(array $data, array $labels): array
    {
        $fields = [];
        foreach ($labels as $field => $label) {
            $fields[$label] = $this->value($data[$field] ?? null);
        }

        return $fields;
    }

    private function value(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : 'Não informado';
    }
}
