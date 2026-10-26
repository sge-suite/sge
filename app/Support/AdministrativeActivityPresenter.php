<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Enums\EducationLevel;
use App\Enums\EmancipationEvidenceStatus;
use App\Enums\EvaluationConcept;
use App\Enums\EvaluationStatus;
use App\Enums\GeneratedDocumentOrigin;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Enums\InternshipCancellationRequestStatus;
use App\Enums\InternshipRequestCorrectionStatus;
use App\Enums\InternshipRequestStatus;
use App\Enums\InternshipStatus;
use App\Enums\LegalCapacityDeclaration;
use App\Enums\PartyDocumentType;
use App\Enums\RegistrationRequestStatus;
use App\Enums\TemplateRemuneration;
use App\Models\Address;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\City;
use App\Models\Course;
use App\Models\DocumentTemplate;
use App\Models\EmancipationEvidence;
use App\Models\GeneratedDocument;
use App\Models\GrantingParty;
use App\Models\GrantingPartyRegistrationRequest;
use App\Models\Holiday;
use App\Models\HolidayRecurrence;
use App\Models\Internship;
use App\Models\InternshipCalendarOverride;
use App\Models\InternshipCancellationRequest;
use App\Models\InternshipPause;
use App\Models\InternshipRequest;
use App\Models\InternshipRequestCorrection;
use App\Models\InternshipType;
use App\Models\InternshipWorkSchedule;
use App\Models\SupervisorEvaluation;
use App\Models\SupervisorRegistrationRequest;
use App\Models\TemplateVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class AdministrativeActivityPresenter
{
    public function __construct(private ActiveAffiliationContext $context, private AddressActivityContext $addresses,
        private ActivityNavigation $navigation) {}

    /** @param Collection<int, Activity> $activities */
    public function prepare(Collection $activities): void
    {
        $this->addresses->prepare($activities);
    }

    /** @return array{actor: string, subject: string, event: string, occurred_at: string, is_creation: bool, is_download: bool, subject_url: ?string, actor_url: ?string, details: array<string, array{value: string, url: ?string}>, changes: list<array{field: string, before: string, after: string, before_url?: ?string, after_url?: ?string}>} */
    public function present(Activity $activity): array
    {
        return [
            ...$this->summary($activity),
            'is_creation' => $activity->event === 'created',
            'is_download' => in_array($activity->event, ['document_download_transmitted', 'document_download_failed'], true),
            'subject_url' => $this->navigation->forModel($activity->subject),
            'actor_url' => $this->navigation->forModel($activity->causer)
                ?? $this->navigation->forReference('user_id', $activity->properties?->get('user_id')),
            'details' => $this->details($activity),
            'changes' => $this->changes($activity),
        ];
    }

    /** @return array<string, string> */
    public static function eventOptions(): array
    {
        return ['created' => 'Criação', 'updated' => 'Alteração', 'deleted' => 'Exclusão', 'restored' => 'Restauração',
            'end_date_calculated' => 'Previsão de término calculada', 'external_document_registered' => 'Documento externo registrado',
            'expired_document_signature_confirmed' => 'Assinatura conferida após análise da data vencida',
            'document_date_correction_requested' => 'Correção da data inicial solicitada',
            'signature_notice_requested' => 'Avisos de assinatura solicitados', 'password_changed' => 'Senha alterada', 'template_version_uploaded' => 'Arquivo DOCX enviado',
            'template_current_version_changed' => 'Versão vigente do template alterada',
            'document_download_transmitted' => 'Documento enviado para download', 'document_download_failed' => 'Falha no download'];
    }

    /** @return array<string, array{value: string, url: ?string}> */
    private function details(Activity $activity): array
    {
        $details = [];
        foreach ($this->downloadDetails($activity) as $label => $value) {
            $details[$label] = ['value' => $value, 'url' => null];
        }
        if (isset($details['Registro do documento'])) {
            $details['Registro do documento']['url'] = $this->navigation->forModel($activity->subject);
        }
        $fields = ['internship_id' => 'Estágio', 'internship_request_id' => 'Solicitação de estágio',
            'campus_id' => 'Campus', 'course_id' => 'Curso', 'internship_type_id' => 'Tipo de estágio',
            'granting_party_id' => 'Concedente', 'user_id' => 'Conta vinculada',
            'student_affiliation_id' => 'Vínculo discente', 'affiliation_id' => 'Vínculo responsável',
            'supervisor_affiliation_id' => 'Vínculo supervisor', 'advisor_affiliation_id' => 'Vínculo orientador',
            'document_template_id' => 'Modelo do documento', 'template_version_id' => 'Versão do modelo',
            'previous_version_id' => 'Versão anterior do modelo', 'current_version_id' => 'Versão vigente do modelo',
            'current_supervisor_evaluation_id' => 'Avaliação vigente',
            'evaluation_released_by_affiliation_id' => 'Avaliação solicitada por',
            'uploaded_by_affiliation_id' => 'Versão enviada por', 'released_by_affiliation_id' => 'Estágio liberado por'];
        $recorded = $activity->attribute_changes?->get('attributes', []) ?? [];
        $subject = $activity->subject;
        foreach ($fields as $field => $label) {
            $property = in_array($field, ['user_id', 'affiliation_id'], true) ? null : $activity->properties?->get($field);
            $isRecorded = array_key_exists($field, $recorded) || $property !== null;
            $value = array_key_exists($field, $recorded) ? $recorded[$field]
                : ($property ?? $subject?->getAttribute($field));
            if ($field === 'document_template_id' && $value === null && $subject instanceof GeneratedDocument) {
                $value = data_get($subject->snapshot, 'template_id');
            }
            if ($this->referenceId($value) === null) {
                continue;
            }
            $url = $this->navigation->forReference($field, $value);
            $details[$label] = ['value' => $details[$label]['value'] ?? '#'.$value.($isRecorded ? '' : ' (referência atual)'), 'url' => $url];
            if ($field === 'document_template_id' && isset($details['Nome do modelo'])) {
                $details['Nome do modelo']['url'] = $url;
            }
        }
        if ($activity->event === 'signature_notice_requested') {
            $details['Local para assinatura'] = ['value' => (string) $activity->properties?->get('signature_availability_location'), 'url' => null];
        }
        $protocol = $activity->properties?->get('protocol_number');
        if (is_string($protocol) && $protocol !== '') {
            $details['Número de protocolo'] = ['value' => $protocol, 'url' => null];
        }
        $categories = $activity->properties?->get('signature_recipient_categories');
        if (is_array($categories)) {
            $labels = ['student' => 'Discente', 'advisor' => 'Orientador', 'supervisor' => 'Supervisor', 'granting_party' => 'Concedente'];
            $details['Avisos de assinatura'] = ['value' => collect($categories)->map(fn ($category): string => $labels[$category] ?? 'Destinatário')->implode(', '), 'url' => null];
            $details['Quantidade de avisos'] = ['value' => (string) count($categories), 'url' => null];
        }
        $reason = $activity->properties?->get('reason');
        if ($activity->event === 'template_current_version_changed' && is_string($reason)) {
            $details['Motivo da mudança de versão'] = ['value' => match ($reason) {
                'selection' => 'Seleção manual', 'upload' => 'Envio de nova versão', 'deletion' => 'Exclusão de versão',
                default => 'Alteração registrada',
            }, 'url' => null];
        }

        return $details;
    }

    /** @return array{actor: string, subject: string, event: string, occurred_at: string} */
    public function summary(Activity $activity): array
    {
        return [
            'actor' => $this->actor($activity),
            'subject' => $this->subject($activity),
            'event' => match ($activity->event) {
                'created' => $activity->subject_type === (new TemplateVersion)->getMorphClass() ? 'Versão de template criada' : 'Criação',
                'updated' => 'Alteração',
                'deleted' => $activity->subject_type === (new TemplateVersion)->getMorphClass() ? 'Arquivo DOCX excluído' : 'Exclusão',
                'restored' => 'Restauração',
                'signature_notice_requested' => 'Avisos de assinatura solicitados', 'password_changed' => 'Senha alterada',
                'template_version_uploaded' => 'Arquivo DOCX enviado',
                'document_download_transmitted' => 'Documento enviado para download'.$this->downloadFormatLabel($activity),
                'document_download_failed' => 'Falha no download do documento'.$this->downloadFormatLabel($activity),
                'template_current_version_changed' => $activity->properties?->get('version') ? 'Versão v'.$activity->properties->get('version').' definida como vigente' : 'Vigência do template removida',
                default => 'Evento registrado',
            },
            'occurred_at' => formatDateTime($activity->created_at),
        ];
    }

    private function downloadFormatLabel(Activity $activity): string
    {
        return match ($activity->properties?->get('format')) {
            'docx' => ' (DOCX)',
            'pdf' => ' (PDF)',
            default => '',
        };
    }

    /** @return array<string, string> */
    private function downloadDetails(Activity $activity): array
    {
        if ($activity->subject_type !== (new GeneratedDocument)->getMorphClass()
            || ! in_array($activity->event, ['document_download_transmitted', 'document_download_failed'], true)) {
            return [];
        }

        $document = $activity->subject;
        $properties = $activity->properties;
        $type = $properties?->get('document_type') ?? ($document instanceof GeneratedDocument ? $document->type->value : null);
        $name = $properties?->get('document_name') ?? ($document instanceof GeneratedDocument ? data_get($document->snapshot, 'template_name') : null);
        $internshipId = $properties?->get('internship_id') ?? ($document instanceof GeneratedDocument ? $document->internship_id : null);
        $versionId = $properties?->get('template_version_id') ?? ($document instanceof GeneratedDocument ? $document->template_version_id : null);
        $generatedAt = $properties?->get('generated_at') ?? ($document instanceof GeneratedDocument ? $document->generated_at : null);
        $format = $properties?->get('format');
        $filename = $properties?->get('output_filename');
        if ($filename === null && $document instanceof GeneratedDocument && $document->output_filename !== null && in_array($format, ['docx', 'pdf'], true)) {
            $filename = app(GeneratedDocumentFilename::class)->forDocument($document, $format);
        }

        return [
            'Documento' => is_string($type) ? (GeneratedDocumentType::tryFrom($type)?->label() ?? 'Tipo não registrado') : 'Tipo não registrado',
            'Nome do modelo' => is_string($name) && $name !== '' ? $name : 'Não registrado',
            'Registro do documento' => '#'.$activity->subject_id,
            'Estágio' => is_numeric($internshipId) ? '#'.$internshipId : 'Não registrado',
            'Formato solicitado' => match ($format) {
                'docx' => 'DOCX', 'pdf' => 'PDF', default => 'Não registrado'
            },
            'Nome do arquivo' => is_string($filename) && $filename !== '' ? $filename : 'Não registrado',
            'Versão do modelo' => is_numeric($versionId) ? 'Registro #'.$versionId : 'Não registrada',
            'Documento gerado em' => $generatedAt === null ? 'Não registrado' : formatDateTime($generatedAt),
        ];
    }

    private function actor(Activity $activity): string
    {
        $causer = $activity->causer;

        if ($causer instanceof Affiliation) {
            return $causer->user->name
                .' · '.$causer->type->label().' · Vínculo #'.$activity->causer_id;
        }

        if ($causer instanceof User) {
            return $causer->name.' · Conta #'.$activity->causer_id;
        }

        if ($activity->causer_type === (new Affiliation)->getMorphClass()) {
            $userId = $activity->properties?->get('user_id');

            return 'Vínculo #'.$activity->causer_id.' (indisponível)'
                .(is_int($userId) ? ' · Conta #'.$userId : '');
        }

        if ($activity->causer_type === (new User)->getMorphClass()) {
            return 'Conta #'.$activity->causer_id.' (indisponível)';
        }

        return match ($activity->properties?->get('actor')) {
            'terminal' => 'Terminal',
            'system' => 'Sistema',
            default => 'Autor não registrado',
        };
    }

    private function subject(Activity $activity): string
    {
        if ($activity->subject_type === (new GeneratedDocument)->getMorphClass()
            && in_array($activity->event, ['document_download_transmitted', 'document_download_failed'], true)) {
            $details = $this->downloadDetails($activity);

            return $details['Documento'].' #'.$activity->subject_id
                .($details['Nome do modelo'] === 'Não registrado' ? '' : ' · '.$details['Nome do modelo'])
                .($details['Estágio'] === 'Não registrado' ? '' : ' · Estágio '.$details['Estágio']);
        }
        if ($activity->subject_type === (new Address)->getMorphClass()) {
            $owners = collect($this->addresses->forActivity($activity))->map(function (array $owner): string {
                $label = match ($owner['type']) {
                    (new Campus)->getMorphClass() => 'Campus',
                    (new GrantingParty)->getMorphClass() => 'Parte concedente',
                    default => 'Cadastro',
                };

                return $label.' #'.$owner['id'].($owner['name'] !== '' ? ' · '.$owner['name'].' (nome registrado)' : '');
            })->implode('; ');

            return 'Endereço #'.$activity->subject_id.($owners !== '' ? ' · '.$owners : ' (cadastro indisponível)');
        }

        $label = match ($activity->subject_type) {
            (new DocumentTemplate)->getMorphClass() => 'Template DOCX',
            (new GeneratedDocument)->getMorphClass() => 'Documento do estágio',
            (new TemplateVersion)->getMorphClass() => 'Versão de template',
            (new Internship)->getMorphClass() => 'Estágio',
            (new SupervisorEvaluation)->getMorphClass() => 'Avaliação do supervisor',
            (new InternshipCancellationRequest)->getMorphClass() => 'Pedido de cancelamento',
            (new InternshipPause)->getMorphClass() => 'Pausa de estágio',
            (new InternshipWorkSchedule)->getMorphClass() => 'Vigência de jornada',
            (new InternshipCalendarOverride)->getMorphClass() => 'Exceção do calendário de estágio',
            (new InternshipRequest)->getMorphClass() => 'Solicitação de estágio',
            (new InternshipRequestCorrection)->getMorphClass() => 'Correção de solicitação',
            (new GrantingPartyRegistrationRequest)->getMorphClass() => 'Pedido de concedente',
            (new SupervisorRegistrationRequest)->getMorphClass() => 'Pedido de supervisor',
            (new EmancipationEvidence)->getMorphClass() => 'Análise de emancipação',
            (new Holiday)->getMorphClass() => 'Feriado',
            (new HolidayRecurrence)->getMorphClass() => 'Recorrência municipal',
            (new Campus)->getMorphClass() => 'Campus',
            (new Course)->getMorphClass() => 'Curso',
            (new InternshipType)->getMorphClass() => 'Tipo de estágio',
            (new GrantingParty)->getMorphClass() => 'Parte concedente',
            (new User)->getMorphClass() => 'Conta',
            (new Affiliation)->getMorphClass() => 'Vínculo',
            default => 'Registro',
        };
        $subject = $activity->subject;
        $recorded = $activity->attribute_changes;
        $name = data_get($recorded, 'attributes.name') ?? data_get($recorded, 'old.name');

        if (is_string($name) && $name !== '') {
            return $label.' #'.$activity->subject_id.' · '.$name.' (nome registrado)';
        }

        if ($subject instanceof Campus || $subject instanceof Course || $subject instanceof User || $subject instanceof GrantingParty || $subject instanceof InternshipType) {
            return $label.' #'.$activity->subject_id.' · '.$subject->name.' (nome atual)';
        }

        if ($subject instanceof Affiliation) {
            return $label.' #'.$activity->subject_id.' · '.$subject->type->label()
                .' · '.$subject->user->name.' (identificação atual)';
        }

        return $label.' #'.$activity->subject_id.($subject === null ? ' (indisponível)' : '');
    }

    /** @return list<array{field: string, before: string, after: string, before_url?: ?string, after_url?: ?string}> */
    private function changes(Activity $activity): array
    {
        $fields = match ($activity->subject_type) {
            (new SupervisorEvaluation)->getMorphClass() => [
                'internship_id' => 'Estágio', 'supervisor_affiliation_id' => 'Supervisor', 'status' => 'Situação',
                'hours_requirement_met' => 'Carga horária cumprida', 'estimated_hours_remaining' => 'Horas faltantes',
                'has_academic_background' => 'Formação na área', 'job_role' => 'Cargo / função',
                'training_course' => 'Curso de formação', 'education_level' => 'Nível de formação', 'experience_time' => 'Experiência profissional',
                ...SupervisorEvaluation::criteria(), ...SupervisorEvaluation::comments(), 'submitted_at' => 'Enviada em',
                'reviewed_at' => 'Revisada em', 'review_notes' => 'Justificativa da revisão',
                'cancelled_at' => 'Cancelada em', 'cancellation_reason' => 'Motivo do cancelamento',
            ],
            (new InternshipCancellationRequest)->getMorphClass() => ['internship_id' => 'Estágio', 'reason' => 'Motivo do pedido', 'status' => 'Situação do pedido', 'reviewed_at' => 'Decidido em', 'decision_reason' => 'Justificativa da decisão', 'effective_date' => 'Data efetiva do cancelamento'],
            (new GeneratedDocument)->getMorphClass() => ['internship_id' => 'Estágio', 'template_version_id' => 'Versão do template',
                'origin' => 'Origem', 'type' => 'Tipo documental', 'status' => 'Situação do documento', 'template_sha256' => 'SHA-256 do template',
                'signature_availability_location' => 'Local para assinatura', 'protocol_number' => 'Número de protocolo', 'generated_at' => 'Gerado em', 'cancelled_at' => 'Cancelado em', 'cancellation_reason' => 'Motivo do cancelamento'],
            (new DocumentTemplate)->getMorphClass() => ['name' => 'Nome', 'description' => 'Descrição', 'document_type' => 'Tipo documental', 'remuneration' => 'Remuneração do modelo', 'campus_id' => 'Campus', 'deactivated_at' => 'Desativado em'],
            (new TemplateVersion)->getMorphClass() => ['version' => 'Versão', 'file_sha256' => 'SHA-256', 'file_size' => 'Tamanho', 'detected_variables' => 'Variáveis detectadas', 'validation_report' => 'Relatório de validação', 'uploaded_by_affiliation_id' => 'Vínculo de envio', 'validated_at' => 'Validada em (histórico)', 'validated_by_affiliation_id' => 'Vínculo de validação (histórico)'],
            (new Internship)->getMorphClass() => [
                'status' => 'Situação', 'released_at' => 'Liberado em', 'released_by_affiliation_id' => 'Liberado por', 'projected_end_date' => 'Término previsto',
                'student_year_semester' => 'Ano/semestre',
                'protocol_number' => 'Protocolo do estágio (histórico)', 'observations' => 'Observações',
                'internship_type_id' => 'Tipo de estágio', 'advisor_affiliation_id' => 'Orientador', 'co_advisor_name' => 'Co-orientador',
                'supervisor_affiliation_id' => 'Supervisor', 'granting_party_id' => 'Concedente',
                'granting_party_snapshot' => 'Dados da concedente no estágio', 'workplace_address_id' => 'Endereço do local de estágio',
                'activities' => 'Atividades', 'internship_sector' => 'Setor/área',
                'planned_start_date' => 'Início planejado', 'weekly_hours' => 'Jornada semanal', 'planned_pauses' => 'Pausas previstas',
                'is_remunerated' => 'Remunerado', 'grant_value' => 'Bolsa', 'transportation_allowance' => 'Auxílio-transporte',
                'evaluation_released_at' => 'Avaliação solicitada em', 'evaluation_released_by_affiliation_id' => 'Vínculo solicitante da avaliação',
                'current_supervisor_evaluation_id' => 'Avaliação vigente', 'supervisor_grade' => 'Nota do supervisor', 'consolidated_grade' => 'Nota consolidada',
            ],
            (new InternshipPause)->getMorphClass() => [
                'internship_id' => 'Estágio', 'starts_at' => 'Início da pausa', 'ends_at' => 'Fim da pausa', 'reason' => 'Motivo',
            ],
            (new InternshipWorkSchedule)->getMorphClass() => [
                'internship_id' => 'Estágio', 'starts_on' => 'Início da vigência', 'ends_on' => 'Fim da vigência',
                'weekly_hours' => 'Jornada semanal', 'generated_document_id' => 'Aditivo assinado',
            ],
            (new InternshipCalendarOverride)->getMorphClass() => [
                'internship_id' => 'Estágio', 'date' => 'Data da exceção', 'is_working_day' => 'Expediente liberado', 'reason' => 'Justificativa',
            ],
            (new InternshipRequest)->getMorphClass() => [
                'affiliation_id' => 'Vínculo discente', 'course_id' => 'Curso', 'internship_type_id' => 'Tipo de estágio',
                'advisor_affiliation_id' => 'Orientador', 'co_advisor_name' => 'Co-orientador', 'granting_party_id' => 'Concedente',
                'granting_party_registration_request_id' => 'Pedido de concedente', 'supervisor_affiliation_id' => 'Supervisor',
                'supervisor_registration_request_id' => 'Pedido de supervisor', 'student_year_semester' => 'Ano/semestre',
                'legal_capacity_declaration' => 'Capacidade civil', 'activities' => 'Atividades', 'internship_sector' => 'Setor/área',
                'weekly_hours' => 'Jornada semanal', 'planned_pauses' => 'Pausas previstas',
                'planned_start_date' => 'Início previsto', 'projected_end_date' => 'Término previsto',
                'is_remunerated' => 'Remunerado', 'grant_value' => 'Bolsa', 'transportation_allowance' => 'Auxílio-transporte',
                'observations' => 'Observações', 'status' => 'Situação', 'internship_id' => 'Estágio criado',
                'terms_accepted_at' => 'Ciência do envio', 'decision_reason' => 'Motivo da recusa',
            ],
            (new InternshipRequestCorrection)->getMorphClass() => [
                'internship_request_id' => 'Solicitação de estágio', 'message' => 'Orientações', 'general_message' => 'Orientação geral',
                'affected_sections' => 'Etapas devolvidas', 'section_messages' => 'Mensagens por etapa', 'status' => 'Situação',
                'responded_at' => 'Respondida em', 'resolved_at' => 'Resolvida em',
            ],
            (new SupervisorRegistrationRequest)->getMorphClass() => [
                'name' => 'Nome', 'cpf' => 'CPF', 'phone' => 'Telefone', 'email' => 'E-mail', 'job_role' => 'Cargo/função',
                'has_academic_background' => 'Possui formação na área', 'training_course' => 'Curso de formação',
                'education_level' => 'Nível de formação', 'experience_time' => 'Tempo de experiência',
                'status' => 'Situação', 'supervisor_affiliation_id' => 'Supervisor associado',
                'reviewed_at' => 'Analisado em', 'decision_reason' => 'Motivo da decisão',
            ],
            (new GrantingPartyRegistrationRequest)->getMorphClass() => [
                'campus_id' => 'Campus', 'document_type' => 'Tipo de documento', 'document_number' => 'CPF/CNPJ',
                'name' => 'Nome / razão social', 'street' => 'Logradouro', 'number' => 'Número', 'neighborhood' => 'Bairro',
                'zip_code' => 'CEP', 'city_id' => 'Cidade', 'city' => 'Nome da cidade', 'uf' => 'UF',
                'representative_name' => 'Representante', 'representative_role' => 'Cargo do representante',
                'phone' => 'Telefone', 'email' => 'E-mail', 'status' => 'Situação', 'granting_party_id' => 'Concedente associada',
                'reviewed_at' => 'Analisado em', 'decision_reason' => 'Motivo da decisão',
            ],
            (new EmancipationEvidence)->getMorphClass() => [
                'internship_request_id' => 'Solicitação de estágio', 'status' => 'Situação', 'reviewed_at' => 'Analisado em',
            ],
            (new Holiday)->getMorphClass() => ['date' => 'Data', 'name' => 'Nome', 'scope' => 'Escopo', 'state_code' => 'UF', 'city_id' => 'Cidade', 'origin' => 'Origem', 'is_manually_adjusted' => 'Ajuste manual', 'holiday_recurrence_id' => 'Recorrência', 'occurrence_year' => 'Ano da ocorrência'],
            (new HolidayRecurrence)->getMorphClass() => ['name' => 'Nome', 'city_id' => 'Cidade', 'month' => 'Mês', 'day' => 'Dia', 'starts_year' => 'Ano inicial', 'ends_year' => 'Ano final', 'notes' => 'Referência / observação'],
            (new Campus)->getMorphClass() => [
                'name' => 'Nome', 'cnpj' => 'CNPJ', 'phone' => 'Telefone', 'address_id' => 'Endereço',
                'internship_office_email' => 'E-mail institucional do Setor de Estágio',
                'legal_representative_name' => 'Representante legal', 'legal_representative_position' => 'Cargo do representante',
                'insurance_company_name' => 'Seguradora', 'insurance_policy_number' => 'Apólice de seguro',
                'deactivated_at' => 'Desativação',
            ],
            (new Address)->getMorphClass() => [
                'city_id' => 'Cidade', 'street' => 'Logradouro', 'number' => 'Número', 'neighborhood' => 'Bairro', 'zip_code' => 'CEP',
            ],
            (new GrantingParty)->getMorphClass() => [
                'campus_id' => 'Campus', 'document_type' => 'Tipo de documento', 'document_number' => 'CPF/CNPJ',
                'name' => 'Nome / razão social', 'address_id' => 'Endereço', 'representative_name' => 'Representante',
                'representative_role' => 'Cargo do representante', 'phone' => 'Telefone', 'email' => 'E-mail',
                'field_of_activity' => 'Área de atuação', 'professional_council' => 'Conselho profissional',
                'council_registration_number' => 'Registro no conselho', 'credentialing_process_number' => 'Processo de credenciamento',
                'deactivated_at' => 'Desativação',
            ],
            (new InternshipType)->getMorphClass() => [
                'course_id' => 'Curso', 'name' => 'Nome', 'required_hours' => 'Carga horária obrigatória',
                'supervisor_evaluation_weight' => 'Peso da avaliação do supervisor', 'report_weight' => 'Peso do relatório',
                'presentation_weight' => 'Peso da apresentação', 'very_good_value' => 'Conceito muito bom', 'good_value' => 'Conceito bom',
                'satisfactory_value' => 'Conceito satisfatório', 'unsatisfactory_value' => 'Conceito insatisfatório',
                'max_daily_hours' => 'Limite de horas diárias', 'max_weekly_hours' => 'Limite de horas semanais',
                'safety_margin_days' => 'Margem de segurança em dias', 'deactivated_at' => 'Desativação',
            ],
            (new User)->getMorphClass() => ['name' => 'Nome', 'cpf' => 'CPF', 'email' => 'E-mail de login'],
            (new Course)->getMorphClass() => [
                'campus_id' => 'Campus', 'name' => 'Nome',
                'coordination_email' => 'E-mail institucional da Coordenação',
                'primary_coordinator_affiliation_id' => 'Coordenador titular',
                'secondary_coordinator_affiliation_id' => 'Coordenador substituto',
                'deactivated_at' => 'Desativação',
            ],
            (new Affiliation)->getMorphClass() => [
                'user_id' => 'Usuário', 'campus_id' => 'Campus', 'course_id' => 'Curso',
                'type' => 'Tipo de vínculo', 'registration_number' => 'Matrícula',
                'email' => 'E-mail do vínculo', 'deactivated_at' => 'Desativação',
            ],
            default => [],
        };
        $excluded = config('activitylog.default_except_attributes', []);
        $old = $activity->attribute_changes?->get('old', []) ?? [];
        $new = $activity->attribute_changes?->get('attributes', []) ?? [];
        $references = $this->referenceValues(array_diff(array_keys($fields), $excluded), $old, $new);
        $changes = [];

        foreach ($fields as $field => $label) {
            if (in_array($field, $excluded, true) || (! array_key_exists($field, $old) && ! array_key_exists($field, $new))) {
                continue;
            }

            if (array_key_exists($field, $old) && array_key_exists($field, $new) && $old[$field] === $new[$field]) {
                continue;
            }

            $changes[] = [
                'field' => $label,
                'before' => array_key_exists($field, $old) ? $this->value($field, $old[$field], $references, $activity->subject_type) : 'Não registrado',
                'after' => array_key_exists($field, $new) ? $this->value($field, $new[$field], $references, $activity->subject_type) : 'Não registrado',
                'before_url' => $this->navigation->forReference($field, $old[$field] ?? null),
                'after_url' => $this->navigation->forReference($field, $new[$field] ?? null),
            ];
        }

        if ($activity->subject_type === (new Internship)->getMorphClass()) {
            $ruleLabels = ['required_hours' => 'Carga horária exigida', 'max_daily_hours' => 'Limite diário de horas',
                'max_weekly_hours' => 'Limite semanal de horas', 'safety_margin_days' => 'Margem de segurança em dias',
                'allowed_academic_periods' => 'Anos ou semestres permitidos', 'supervisor_evaluation_weight' => 'Peso da avaliação do supervisor',
                'report_weight' => 'Peso do relatório', 'presentation_weight' => 'Peso da apresentação',
                'very_good_value' => 'Conceito Muito bom', 'good_value' => 'Conceito Bom',
                'satisfactory_value' => 'Conceito Satisfatório', 'unsatisfactory_value' => 'Conceito Insatisfatório'];
            $oldRules = $old['internship_type_snapshot']['rules'] ?? [];
            $newRules = $new['internship_type_snapshot']['rules'] ?? [];
            foreach ($ruleLabels as $rule => $label) {
                if ((! array_key_exists($rule, $oldRules) && ! array_key_exists($rule, $newRules))
                    || (array_key_exists($rule, $oldRules) && array_key_exists($rule, $newRules) && $oldRules[$rule] === $newRules[$rule])) {
                    continue;
                }
                $changes[] = ['field' => $label.' no estágio',
                    'before' => array_key_exists($rule, $oldRules) ? $this->value($rule, $oldRules[$rule], []) : 'Não registrado',
                    'after' => array_key_exists($rule, $newRules) ? $this->value($rule, $newRules[$rule], []) : 'Não registrado'];
            }
            $reason = $activity->properties?->get('management_change_reason');
            if (is_string($reason) && $reason !== '') {
                $changes[] = ['field' => 'Motivo da operação', 'before' => 'Não registrado', 'after' => $reason];
            }
            if (in_array('supervisor', $activity->properties?->get('changed_sections', []) ?? [], true)) {
                $changes[] = ['field' => 'Dados do supervisor', 'before' => 'Não registrado', 'after' => 'Atualização registrada'];
            }
        }

        return $changes;
    }

    /** @return array<string, array{model: class-string<Model>, label: string}> */
    private function referenceFields(): array
    {
        return [
            'user_id' => ['model' => User::class, 'label' => 'Conta'],
            'campus_id' => ['model' => Campus::class, 'label' => 'Campus'],
            'course_id' => ['model' => Course::class, 'label' => 'Curso'],
            'internship_type_id' => ['model' => InternshipType::class, 'label' => 'Tipo de estágio'],
            'granting_party_id' => ['model' => GrantingParty::class, 'label' => 'Concedente'],
            'affiliation_id' => ['model' => Affiliation::class, 'label' => 'Vínculo'],
            'advisor_affiliation_id' => ['model' => Affiliation::class, 'label' => 'Vínculo'],
            'supervisor_affiliation_id' => ['model' => Affiliation::class, 'label' => 'Vínculo'],
            'address_id' => ['model' => Address::class, 'label' => 'Endereço'],
            'city_id' => ['model' => City::class, 'label' => 'Cidade'],
            'primary_coordinator_affiliation_id' => ['model' => Affiliation::class, 'label' => 'Vínculo'],
            'secondary_coordinator_affiliation_id' => ['model' => Affiliation::class, 'label' => 'Vínculo'],
        ];
    }

    /**
     * Resolve both sides together so repeated references share one query per model.
     * Only identify references inside the viewer's selected campus.
     *
     * @param  array<int, string>  $fields
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<class-string<Model>, array<int, string>>
     */
    private function referenceValues(array $fields, array $old, array $new): array
    {
        $referenceFields = $this->referenceFields();
        $ids = [];
        foreach ($fields as $field) {
            if (! isset($referenceFields[$field])) {
                continue;
            }
            foreach ([$old[$field] ?? null, $new[$field] ?? null] as $value) {
                $id = $this->referenceId($value);
                if ($id !== null) {
                    $ids[$referenceFields[$field]['model']][$id] = $id;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $actor = auth()->user();
        $selected = $actor === null ? null : $this->context->currentFor($actor, app('session.store'));
        $values = [];
        foreach ($ids as $model => $modelIds) {
            /** @var Builder<Model> $query */
            $query = $model::query();
            $query->whereKey(array_values($modelIds))->select(match ($model) {
                Affiliation::class => ['id', 'user_id'],
                City::class => ['id', 'name', 'state'],
                Address::class => ['id', 'street', 'number', 'neighborhood', 'city_id'],
                default => ['id', 'name'],
            });
            if ($model === Affiliation::class) {
                $query->with('user:id,name');
            } elseif ($model === Address::class) {
                $query->with('city');
            }
            if ($selected?->type === AffiliationType::InternshipOffice) {
                match ($model) {
                    Campus::class => $query->whereKey($selected->campus_id),
                    Course::class, GrantingParty::class => $query->where('campus_id', $selected->campus_id),
                    InternshipType::class => $query->whereHas('course', fn (Builder $query) => $query->where('campus_id', $selected->campus_id)),
                    Affiliation::class => $query->where('campus_id', $selected->campus_id)->where('type', '!=', AffiliationType::SystemAdministrator->value),
                    User::class => $query->whereHas('affiliations', fn (Builder $query): Builder => $query
                        ->where('campus_id', $selected->campus_id)->where('type', '!=', AffiliationType::SystemAdministrator->value)),
                    Address::class => $query->whereIn('id', $this->visibleAddressIds($selected->campus_id)),
                    City::class => $query,
                    default => $query->whereRaw('1 = 0'),
                };
            }
            foreach ($query->get() as $reference) {
                $name = match (true) {
                    $reference instanceof Affiliation => $reference->user->name,
                    $reference instanceof Address => $reference->street.', '.$reference->number.' · '.$reference->neighborhood
                        .' · '.$reference->city->name.'/'.$reference->city->state->value,
                    $reference instanceof City => $reference->name.'/'.$reference->state->value,
                    default => $reference->getAttribute('name'),
                };
                if (is_string($name) && $name !== '') {
                    $values[$model][$reference->getKey()] = $name;
                }
            }
        }

        return $values;
    }

    /** @return Builder<Activity> */
    private function visibleAddressIds(int $campusId): Builder
    {
        $query = Activity::query();
        $this->addresses->apply($query, $campusId);

        return $query->select('subject_id');
    }

    private function referenceId(mixed $value): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    /** @param array<class-string<Model>, array<int, string>> $references */
    private function value(string $field, mixed $value, array $references, ?string $subjectType = null): string
    {
        if ($value === null) {
            return 'Sem valor';
        }

        if ($value === '') {
            return 'Vazio';
        }

        if (is_array($value)) {
            return $this->structuredValue($field, $value);
        }

        if (! is_scalar($value)) {
            return 'Valor não exibido';
        }

        if ($field === 'status') {
            $status = match ($subjectType) {
                (new SupervisorEvaluation)->getMorphClass() => EvaluationStatus::tryFrom((string) $value),
                (new InternshipCancellationRequest)->getMorphClass() => InternshipCancellationRequestStatus::tryFrom((string) $value),
                (new GeneratedDocument)->getMorphClass() => GeneratedDocumentStatus::tryFrom((string) $value),
                (new Internship)->getMorphClass() => InternshipStatus::tryFrom((string) $value),
                (new InternshipRequest)->getMorphClass() => InternshipRequestStatus::tryFrom((string) $value),
                (new InternshipRequestCorrection)->getMorphClass() => InternshipRequestCorrectionStatus::tryFrom((string) $value),
                (new SupervisorRegistrationRequest)->getMorphClass(), (new GrantingPartyRegistrationRequest)->getMorphClass() => RegistrationRequestStatus::tryFrom((string) $value),
                (new EmancipationEvidence)->getMorphClass() => EmancipationEvidenceStatus::tryFrom((string) $value),
                default => null,
            };

            return $status?->label() ?? 'Situação não reconhecida';
        }

        if ($field === 'education_level') {
            return EducationLevel::tryFrom((string) $value)?->label() ?? 'Nível não reconhecido';
        }

        if ($subjectType === (new SupervisorEvaluation)->getMorphClass() && array_key_exists($field, SupervisorEvaluation::criteria())) {
            return EvaluationConcept::tryFrom((string) $value)?->label() ?? 'Conceito não reconhecido';
        }

        if ($field === 'legal_capacity_declaration') {
            return LegalCapacityDeclaration::tryFrom((string) $value)?->label() ?? 'Declaração não reconhecida';
        }

        if (in_array($field, ['grant_value', 'transportation_allowance'], true)) {
            return is_numeric($value) ? formatCurrency((float) $value) : 'Valor não exibido';
        }

        if (in_array($field, ['planned_start_date', 'projected_end_date', 'effective_date'], true)
            || ($subjectType === (new InternshipPause)->getMorphClass() && in_array($field, ['starts_at', 'ends_at'], true))
            || ($subjectType === (new InternshipCalendarOverride)->getMorphClass() && $field === 'date')
            || ($subjectType === (new InternshipWorkSchedule)->getMorphClass() && in_array($field, ['starts_on', 'ends_on'], true))) {
            return formatShort((string) $value);
        }

        if (in_array($field, ['terms_accepted_at', 'reviewed_at', 'responded_at', 'resolved_at', 'generated_at', 'cancelled_at', 'submitted_at', 'evaluation_released_at', 'released_at'], true)) {
            return formatDateTime((string) $value);
        }

        $reference = $this->referenceFields()[$field] ?? null;
        if ($reference !== null) {
            $id = $this->referenceId($value);
            if ($id === null) {
                return 'Valor não exibido';
            }
            $name = $references[$reference['model']][$id] ?? null;

            return $name === null
                ? $reference['label'].' #'.$id.' (identificação indisponível)'
                : $name.' · '.$reference['label'].' #'.$id.' (identificação atual)';
        }

        if (is_bool($value)) {
            return $value ? 'Sim' : 'Não';
        }

        if ($field === 'deactivated_at') {
            return formatDateTime((string) $value);
        }

        if ($subjectType === (new DocumentTemplate)->getMorphClass() && $field === 'remuneration') {
            return TemplateRemuneration::tryFrom((string) $value)?->label() ?? 'Classificação não reconhecida';
        }

        if ($field === 'document_type' && $subjectType === (new DocumentTemplate)->getMorphClass()) {
            return GeneratedDocumentType::tryFrom((string) $value)?->label() ?? 'Tipo não reconhecido';
        }

        if ($field === 'document_type') {
            return PartyDocumentType::tryFrom((string) $value)?->label() ?? 'Tipo não reconhecido';
        }

        if ($subjectType === (new GeneratedDocument)->getMorphClass() && $field === 'origin') {
            return GeneratedDocumentOrigin::tryFrom((string) $value)?->label() ?? 'Origem não reconhecida';
        }

        if ($subjectType === (new GeneratedDocument)->getMorphClass() && $field === 'type') {
            return GeneratedDocumentType::tryFrom((string) $value)?->label() ?? 'Tipo não reconhecido';
        }

        if ($field === 'type') {
            return AffiliationType::tryFrom($value === AffiliationType::LEGACY_CAMPUS_ADMINISTRATOR ? AffiliationType::InternshipOffice->value : (string) $value)?->label() ?? 'Tipo não reconhecido';
        }

        return (string) $value;
    }

    /** @param array<string|int, mixed> $value */
    private function structuredValue(string $field, array $value): string
    {
        if ($value === []) {
            return 'Nenhum item';
        }

        if ($field === 'granting_party_snapshot') {
            $labels = ['name' => 'Nome', 'document_number' => 'CPF/CNPJ', 'phone' => 'Telefone', 'email' => 'E-mail',
                'representative_name' => 'Representante', 'representative_role' => 'Cargo do representante', 'field_of_activity' => 'Área de atuação',
                'professional_council' => 'Conselho profissional', 'council_registration_number' => 'Registro no conselho', 'credentialing_process_number' => 'Processo de credenciamento'];

            return collect($labels)->filter(fn (string $label, string $key): bool => isset($value[$key]) && is_scalar($value[$key]))
                ->map(fn (string $label, string $key): string => $label.': '.$value[$key])->implode('; ');
        }

        if ($field === 'affected_sections') {
            return collect($value)->filter(fn (mixed $section): bool => is_string($section))
                ->map(fn (string $section): string => InternshipRequestCorrection::sectionLabel($section))->implode('; ');
        }

        if ($field === 'allowed_academic_periods') {
            return collect($value)->filter(fn (mixed $period): bool => is_string($period))->implode('; ');
        }

        if ($field === 'section_messages') {
            return collect($value)->filter(fn (mixed $message): bool => is_string($message))
                ->map(fn (string $message, int|string $section): string => InternshipRequestCorrection::sectionLabel((string) $section).': '.$message)->implode('; ');
        }

        if ($field === 'weekly_hours') {
            $days = ['sunday' => 'Domingo', 'monday' => 'Segunda-feira', 'tuesday' => 'Terça-feira', 'wednesday' => 'Quarta-feira',
                'thursday' => 'Quinta-feira', 'friday' => 'Sexta-feira', 'saturday' => 'Sábado'];

            return collect($days)->filter(fn (string $label, string $day): bool => array_key_exists($day, $value))
                ->map(fn (string $label, string $day): string => $label.': '.$value[$day].' h')->implode('; ');
        }

        if ($field === 'planned_pauses') {
            return collect($value)->filter(fn (mixed $pause): bool => is_array($pause))
                ->map(fn (array $pause): string => formatShort($pause['starts_at'] ?? null).' a '.formatShort($pause['ends_at'] ?? null)
                    .(filled($pause['reason'] ?? null) ? ' — '.$pause['reason'] : ''))->implode('; ');
        }

        return 'Valor não exibido';
    }
}
