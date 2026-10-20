<?php

namespace App\Support;

use App\Enums\EducationLevel;
use App\Enums\LegalCapacityDeclaration;
use App\Helpers\BrazilianAddressHelper;
use App\Helpers\BrazilianContactHelper;
use App\Helpers\BrazilianDocumentHelper;
use App\Helpers\CurrencyHelper;
use App\Helpers\DateHelper;
use App\Helpers\NumberToWordsHelper;
use App\Models\Address;
use App\Models\Affiliation;
use App\Models\DocumentTemplate;
use App\Models\Internship;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

class GeneratedDocumentSnapshot
{
    public const COORDINATOR_VARIABLES = ['DOCUMENTO_DATA_EMISSAO', 'DOCUMENTO_DATA_EMISSAO_EXTENSO', 'DOCUMENTO_TITULO',
        'DISCENTE_NOME', 'DISCENTE_MATRICULA', 'DISCENTE_PERIODO', 'CURSO_NOME', 'CONCEDENTE_NOME', 'COORDENADOR_NOME',
        'ORIENTADOR_NOME', 'ORIENTADOR_EMAIL', 'ORIENTADOR_MATRICULA', 'ESTAGIO_DATA_INICIO', 'ESTAGIO_DATA_TERMINO_PREVISTA', 'ESTAGIO_PERIODO'];

    public function __construct(private TemplateVariableCatalog $catalog, private TemplateTextFormatter $formatter) {}

    public function allowedForCoordinator(string $name): bool
    {
        return str_starts_with($name, 'CAMPUS_') || in_array($name, self::COORDINATOR_VARIABLES, true);
    }

    /**
     * @param  list<string>  $markers
     * @return array<string, mixed>
     */
    public function build(Internship $internship, DocumentTemplate $template, Affiliation $actor, array $markers, DateTimeInterface $generatedAt, bool $coordinator): array
    {
        $available = $this->catalog->available();
        $values = $replacements = [];
        foreach ($markers as $expression) {
            $parsed = TemplateTextFormatter::parse($expression);
            if ($parsed === null || ! isset($available[$parsed['name']])) {
                throw ValidationException::withMessages(['document' => 'O template contém uma variável indisponível.']);
            }
            $name = $parsed['name'];
            if ($coordinator && ! $this->allowedForCoordinator($name)) {
                throw ValidationException::withMessages(['document' => 'O template solicita dados fora do contexto de emissão do coordenador. Peça ao Setor que revise o modelo.']);
            }
            if (! array_key_exists($name, $values)) {
                $value = $this->value($name, $internship, $template, $actor, $generatedAt, $coordinator);
                if ($value === null || ($value === '' && ! $this->mayBeEmpty($name, $internship))) {
                    throw ValidationException::withMessages(['document' => 'Não foi possível preencher '.$name.'. Confira os dados do estágio ou escolha outro template.']);
                }
                if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1 || strlen($value) > 100000) {
                    throw ValidationException::withMessages(['document' => 'O valor de '.$name.' não pode ser inserido no documento. Revise os dados de origem.']);
                }
                $values[$name] = $value;
            }
            $replacements[$expression] = $name === 'CAMPUS_IDENTIFICACAO'
                ? $this->formatter->campusIdentification($values[$name], $parsed['modifier'])
                : [['text' => $this->formatter->format($values[$name], $parsed['modifier'])]];
        }

        return ['schema_revision' => 1, 'renderer_revision' => DocxRenderer::REVISION,
            'catalog_revision' => TemplateVariableCatalog::REVISION, 'template_name' => $template->name,
            'template_id' => $template->id, 'campus_id' => $internship->course->campus_id,
            'actor' => ['user_id' => $actor->user_id, 'affiliation_id' => $actor->id, 'name' => $actor->user->name,
                'role' => $actor->type->value],
            'values' => $values, 'replacements' => $replacements];
    }

    private function value(string $name, Internship $stage, DocumentTemplate $template, Affiliation $actor, DateTimeInterface $generatedAt, bool $coordinator): ?string
    {
        $student = ['NOME' => 'name', 'EMAIL' => 'email', 'TELEFONE' => 'phone', 'MATRICULA' => 'registration_number',
            'PERIODO' => 'student_year_semester', 'NASCIMENTO' => 'birth_date', 'CPF' => 'cpf', 'RG' => 'rg',
            'RG_ORGAO' => 'rg_issuer', 'RG_DATA_EMISSAO' => 'rg_issue_date'];
        $party = ['NOME' => 'name', 'DOCUMENTO' => 'document_number', 'EMAIL' => 'email', 'TELEFONE' => 'phone',
            'AREA_ATUACAO' => 'field_of_activity', 'REPRESENTANTE_NOME' => 'representative_name', 'REPRESENTANTE_CARGO' => 'representative_role',
            'CONSELHO_PROFISSIONAL' => 'professional_council', 'REGISTRO_CONSELHO' => 'council_registration_number', 'PROCESSO_CREDENCIAMENTO' => 'credentialing_process_number'];
        foreach (['DISCENTE_' => [$student, $stage->student_snapshot], 'CONCEDENTE_' => [$party, $stage->granting_party_snapshot]] as $prefix => [$fields, $snapshot]) {
            $suffix = substr($name, strlen($prefix));
            if (str_starts_with($name, $prefix) && isset($fields[$suffix])) {
                $raw = $this->text($snapshot[$fields[$suffix]] ?? null);

                return $raw === null ? null : match ($suffix) {
                    'NASCIMENTO', 'RG_DATA_EMISSAO' => DateHelper::formatShort($raw),
                    'CPF' => BrazilianDocumentHelper::formatCpf($raw),
                    'DOCUMENTO' => BrazilianDocumentHelper::formatCpfCnpj($raw),
                    'TELEFONE' => BrazilianContactHelper::formatPhone($raw),
                    default => $raw,
                };
            }
        }
        if (preg_match('/^(CAMPUS|DISCENTE|CONCEDENTE)_(ENDERECO|LOGRADOURO|NUMERO|BAIRRO|CIDADE|UF|CEP)$/', $name, $addressMatch) === 1) {
            $address = match ($addressMatch[1]) {
                'CAMPUS' => $stage->course->campus->address,
                'DISCENTE' => $stage->studentAddress,
                'CONCEDENTE' => $stage->workplaceAddress,
            };

            return $this->addressValue($addressMatch[2], $address);
        }
        $campus = $stage->course->campus;
        $campusFields = ['CAMPUS_NOME' => 'name', 'CAMPUS_CNPJ' => 'cnpj', 'CAMPUS_TELEFONE' => 'phone',
            'CAMPUS_REPRESENTANTE_NOME' => 'legal_representative_name', 'CAMPUS_REPRESENTANTE_CARGO' => 'legal_representative_position',
            'SEGURO_SEGURADORA' => 'insurance_company_name', 'SEGURO_APOLICE' => 'insurance_policy_number'];
        if (isset($campusFields[$name])) {
            $raw = $this->text($campus->{$campusFields[$name]});

            return $raw === null ? null : match ($name) {
                'CAMPUS_CNPJ' => BrazilianDocumentHelper::formatCnpj($raw),
                'CAMPUS_TELEFONE' => BrazilianContactHelper::formatPhone($raw),
                default => $raw,
            };
        }
        if (str_starts_with($name, 'RESPONSAVEL_') || $name === 'BLOCO_RESPONSAVEL_LEGAL') {
            if (! $this->needsGuardian($stage)) {
                return ($stage->student_snapshot['legal_capacity_declaration'] ?? null) === null ? null : '';
            }
            $fields = ['RESPONSAVEL_NOME' => 'legal_guardian_name', 'RESPONSAVEL_CPF' => 'legal_guardian_cpf',
                'RESPONSAVEL_PARENTESCO' => 'legal_guardian_kinship', 'RESPONSAVEL_EMAIL' => 'legal_guardian_email'];
            if (isset($fields[$name])) {
                $raw = $this->text($stage->student_snapshot[$fields[$name]] ?? null);

                return $name === 'RESPONSAVEL_CPF' && $raw !== null ? BrazilianDocumentHelper::formatCpf($raw) : $raw;
            }
            $guardian = $this->text($stage->student_snapshot['legal_guardian_name'] ?? null);
            $cpf = $this->text($stage->student_snapshot['legal_guardian_cpf'] ?? null);

            return $guardian === null || $cpf === null ? null : 'Representado(a) por '.$guardian.', CPF '.BrazilianDocumentHelper::formatCpf($cpf).'.';
        }
        if (str_starts_with($name, 'SUPERVISOR_')) {
            $fields = ['NOME' => 'name', 'EMAIL' => 'email', 'TELEFONE' => 'phone', 'CARGO' => 'job_role'];
            $suffix = substr($name, 11);
            if (isset($fields[$suffix])) {
                $raw = $this->text($stage->supervisor_snapshot[$fields[$suffix]] ?? null);

                return $suffix === 'TELEFONE' && $raw !== null ? BrazilianContactHelper::formatPhone($raw) : $raw;
            }
            $academic = $stage->supervisor_snapshot['has_academic_background'] ?? null;
            $education = EducationLevel::tryFrom((string) ($stage->supervisor_snapshot['education_level'] ?? ''))?->label();
            $course = $this->text($stage->supervisor_snapshot['training_course'] ?? null);
            $training = $education === null || $course === null ? null : $education.' em '.$course;
            $experience = $this->text($stage->supervisor_snapshot['experience_time'] ?? null);

            return match ($suffix) {
                'FORMACAO' => $academic === false ? '' : $training,
                'EXPERIENCIA' => $academic === true ? '' : $experience,
                'QUALIFICACAO' => $academic === null ? null : ($academic ? $training : $experience),
                default => null,
            };
        }
        $hours = $stage->weekly_hours;
        $needsSchedule = str_starts_with($name, 'ESTAGIO_CARGA_') || $name === 'ESTAGIO_JORNADA_DESCRICAO';
        $schedule = $needsSchedule ? $stage->workSchedules->filter(fn ($schedule): bool => $schedule->starts_on->lte($generatedAt)
            && ($schedule->ends_on === null || $schedule->ends_on->gte($generatedAt)))->sortByDesc('starts_on')->first() : null;
        if ($schedule !== null) {
            $hours = $schedule->weekly_hours;
        }
        $total = $stage->internship_type_snapshot['rules']['required_hours'] ?? null;
        $maxHours = $hours === [] ? null : max($hours);
        $grant = $stage->is_remunerated ? $this->text($stage->grant_value) : null;
        $transport = $this->text($stage->transportation_allowance);

        return match ($name) {
            'DOCUMENTO_DATA_EMISSAO' => DateHelper::formatShort($generatedAt),
            'DOCUMENTO_DATA_EMISSAO_EXTENSO' => DateHelper::format($generatedAt),
            'DOCUMENTO_TITULO' => $template->name,
            'CAMPUS_IDENTIFICACAO' => $this->text($campus->address->city->name),
            'CURSO_NOME' => $stage->course->name,
            'COORDENADOR_NOME' => $coordinator ? $actor->user->name : null,
            'ORIENTADOR_NOME' => $this->text($stage->advisorAffiliation?->user?->name),
            'ORIENTADOR_EMAIL' => $this->text($stage->advisorAffiliation?->email),
            'ORIENTADOR_MATRICULA' => $this->text($stage->advisorAffiliation?->registration_number),
            'ESTAGIO_TIPO' => $this->text($stage->internship_type_snapshot['name'] ?? null),
            'ESTAGIO_SETOR' => $this->text($stage->internship_sector),
            'ESTAGIO_ATIVIDADES' => $this->text($stage->activities),
            'ESTAGIO_DATA_INICIO' => DateHelper::formatShort($stage->planned_start_date),
            'ESTAGIO_DATA_TERMINO_PREVISTA' => DateHelper::formatShort($stage->projected_end_date),
            'ESTAGIO_PERIODO' => DateHelper::formatShort($stage->planned_start_date).' a '.DateHelper::formatShort($stage->projected_end_date),
            'ESTAGIO_CARGA_TOTAL' => $this->text($total),
            'ESTAGIO_CARGA_TOTAL_EXTENSO' => $total === null ? null : NumberToWordsHelper::cardinal((int) $total),
            'ESTAGIO_CARGA_DIARIA_MAXIMA' => $this->text($maxHours),
            'ESTAGIO_CARGA_DIARIA_MAXIMA_EXTENSO' => $maxHours === null ? null : NumberToWordsHelper::cardinal($maxHours),
            'ESTAGIO_CARGA_SEMANAL' => (string) array_sum($hours),
            'ESTAGIO_CARGA_SEMANAL_EXTENSO' => NumberToWordsHelper::cardinal(array_sum($hours)),
            'ESTAGIO_JORNADA_DESCRICAO' => $this->scheduleDescription($hours),
            'ESTAGIO_REMUNERADO' => $stage->is_remunerated ? 'Sim' : 'Não',
            'ESTAGIO_BOLSA_VALOR' => $grant === null ? null : CurrencyHelper::formatDecimal($grant),
            'ESTAGIO_BOLSA_EXTENSO' => $grant === null ? null : NumberToWordsHelper::brl($grant),
            'ESTAGIO_AUXILIO_TRANSPORTE_VALOR' => $transport === null ? null : CurrencyHelper::formatDecimal($transport),
            'ESTAGIO_AUXILIO_TRANSPORTE_EXTENSO' => $transport === null ? null : NumberToWordsHelper::brl($transport),
            'ESTAGIO_PROTOCOLO_SIGAA' => $this->text($stage->protocol_number),
            'ESTAGIO_NOTA_SUPERVISOR' => $this->text($stage->supervisor_grade),
            'ESTAGIO_NOTA_RELATORIO' => $this->text($stage->report_grade),
            'ESTAGIO_NOTA_APRESENTACAO' => $this->text($stage->presentation_grade),
            'ESTAGIO_NOTA_FINAL' => $this->text($stage->consolidated_grade),
            default => null,
        };
    }

    private function addressValue(string $field, ?Address $address): ?string
    {
        if ($address === null) {
            return null;
        }
        $city = $address->city;
        if ($field === 'ENDERECO') {
            if (blank($address->street) || blank($address->number) || blank($address->neighborhood) || blank($address->zip_code)) {
                return null;
            }

            return $address->street.', '.$address->number.' · '.$address->neighborhood.' · '.$city->name.'/'.$this->text($city->state).' · '.BrazilianAddressHelper::formatCep($address->zip_code);
        }

        return $this->text(match ($field) {
            'LOGRADOURO' => $address->street, 'NUMERO' => $address->number, 'BAIRRO' => $address->neighborhood,
            'CIDADE' => $city->name, 'UF' => $city->state, 'CEP' => filled($address->zip_code) ? BrazilianAddressHelper::formatCep($address->zip_code) : null,
            default => null,
        });
    }

    private function needsGuardian(Internship $stage): bool
    {
        return ($stage->student_snapshot['legal_capacity_declaration'] ?? null) === LegalCapacityDeclaration::Minor->value;
    }

    private function mayBeEmpty(string $name, Internship $stage): bool
    {
        return ((! $this->needsGuardian($stage)) && (str_starts_with($name, 'RESPONSAVEL_') || $name === 'BLOCO_RESPONSAVEL_LEGAL'))
            || ($name === 'SUPERVISOR_FORMACAO' && ($stage->supervisor_snapshot['has_academic_background'] ?? null) === false)
            || ($name === 'SUPERVISOR_EXPERIENCIA' && ($stage->supervisor_snapshot['has_academic_background'] ?? null) === true);
    }

    /** @param array<string, int> $hours */
    private function scheduleDescription(array $hours): string
    {
        $days = ['sunday' => 'Domingo', 'monday' => 'Segunda-feira', 'tuesday' => 'Terça-feira', 'wednesday' => 'Quarta-feira',
            'thursday' => 'Quinta-feira', 'friday' => 'Sexta-feira', 'saturday' => 'Sábado'];
        $parts = [];
        foreach ($days as $day => $label) {
            if (($hours[$day] ?? 0) > 0) {
                $parts[] = $label.': '.$hours[$day].' h';
            }
        }

        return implode('; ', $parts);
    }

    private function text(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
