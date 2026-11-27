<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Enums\InternshipCancellationRequestStatus;
use App\Enums\InternshipStatus;
use App\Models\Affiliation;
use App\Models\Course;
use App\Models\GeneratedDocument;
use App\Models\Internship;
use App\Models\InternshipCancellationRequest;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as LaravelValidator;
use Spatie\Activitylog\Models\Activity;
use stdClass;

class InternshipIndicators
{
    public const COURSES_AND_PERFORMANCE = [
        'courses' => 'Estagiários por curso',
        'advisors' => 'Carga de orientação por docente',
        'parties' => 'Principais concedentes',
        'course_parties' => 'Concedentes por curso',
        'supervisors' => 'Supervisores com mais estagiários (top 5)',
        'grade_distribution' => 'Distribuição das notas finais',
        'grade_averages' => 'Desempenho (notas médias) por curso',
    ];

    public const FLOW_AND_MONITORING = [
        'statuses' => 'Situação geral dos estágios',
        'cancellations' => 'Cancelamentos por motivo',
        'release_deadline' => 'Encaminhamento dentro do prazo por curso',
        'completion_deadline' => 'Conclusão dentro do prazo por curso',
        'processing' => 'Tempo médio de tramitação por mês',
        'addendum_processing' => 'Tramitação dos aditivos por mês',
    ];

    /** @return array<string, array<string, string>> */
    public function sections(Affiliation $affiliation): array
    {
        $sections = ['Cursos e desempenho' => self::COURSES_AND_PERFORMANCE];

        if ($affiliation->type === AffiliationType::InternshipOffice) {
            $sections['Fluxo documental e acompanhamento'] = self::FLOW_AND_MONITORING;
        }

        return $sections;
    }

    /** @return array<string, string> */
    public function allowedMetrics(Affiliation $affiliation): array
    {
        return array_merge(...array_values($this->sections($affiliation)));
    }

    /** @return EloquentBuilder<Course> */
    public function courses(Affiliation $affiliation): EloquentBuilder
    {
        return Course::query()->where('campus_id', $affiliation->campus_id)
            ->when($affiliation->type === AffiliationType::Coordinator, fn (EloquentBuilder $query) => $query->where(
                fn (EloquentBuilder $query) => $query->where('primary_coordinator_affiliation_id', $affiliation->id)
                    ->orWhere('secondary_coordinator_affiliation_id', $affiliation->id)));
    }

    /** @param array<string, mixed> $filters */
    public function validator(Affiliation $affiliation, array $filters): LaravelValidator
    {
        return Validator::make($filters, [
            'metric' => ['required', Rule::in(array_keys($this->allowedMetrics($affiliation)))],
            'scope' => ['nullable', Rule::in(['details', 'summary'])],
            'course' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(InternshipStatus::class)],
            'from' => ['bail', 'nullable', 'date_format:Y-m-d', 'date'],
            'to' => ['bail', 'nullable', 'date_format:Y-m-d', 'date',
                Rule::when(filled($filters['from'] ?? null), ['after_or_equal:from'])],
            'group' => ['nullable', 'string', 'max:100'],
        ], [
            'metric.in' => 'Selecione um indicador disponível para o seu vínculo.',
            'from.date_format' => 'Informe uma data inicial válida.',
            'from.date' => 'Informe uma data inicial válida.',
            'to.date_format' => 'Informe uma data final válida.',
            'to.date' => 'Informe uma data final válida.',
            'to.after_or_equal' => 'A data final deve ser igual ou posterior à inicial.',
        ]);
    }

    public function periodLabel(string $metric): string
    {
        return match ($metric) {
            'cancellations' => 'Data efetiva do cancelamento',
            'processing' => 'Criação do estágio',
            'addendum_processing' => 'Emissão ou registro do aditivo',
            'completion_deadline' => 'Data de conclusão (ou término previsto)',
            default => 'Início previsto',
        };
    }

    public function chartKind(string $metric): string
    {
        return match ($metric) {
            'statuses', 'cancellations' => 'donut',
            'processing', 'addendum_processing' => 'line',
            'grade_distribution' => 'column',
            'release_deadline', 'completion_deadline' => 'stacked',
            default => 'bar',
        };
    }

    public function definition(string $metric): string
    {
        return match ($metric) {
            'courses' => 'Cada estudante conta uma vez por curso. A consulta de registros lista todos os estágios que compõem o total.',
            'advisors' => 'Quantidade de estágios sob orientação de cada docente no recorte selecionado.',
            'parties' => 'Unidades concedentes com maior número de estágios vinculados no recorte selecionado.',
            'course_parties' => 'Cada unidade concedente conta uma vez por curso. A consulta de registros identifica a concedente de cada estágio.',
            'supervisors' => 'Apresenta os cinco supervisores que mais acompanharam estudantes únicos (sem cálculo de média). Você pode consultar os registros de cada supervisor ou do recorte completo.',
            'grade_distribution', 'grade_averages' => 'Considera a nota consolidada dos estágios concluídos. Notas ausentes são contabilizadas separadamente e zeros entram no cálculo.',
            'statuses' => 'Distribuição dos estágios pela situação atual no recorte filtrado.',
            'cancellations' => 'Cancelamentos agrupados pelo motivo consolidado (ou justificativa original normalizada). O Setor de Estágio pode selecionar motivos repetidos para unificá-los de uma só vez.',
            'processing' => 'Média mensal em dias corridos entre a criação do estágio e a liberação para execução. Para consultar os estágios e dias até a liberação, clique em Consultar registros.',
            'addendum_processing' => 'Média mensal em dias corridos entre a emissão ou registro do aditivo e a conferência da assinatura pelo Setor.',
            'release_deadline' => 'Considera no prazo quando a liberação inicial ocorreu até o início previsto e eventuais aditivos foram conferidos até a previsão de término vigente antes do aditivo.',
            'completion_deadline' => 'Considera no prazo quando a conclusão do estágio foi registrada até a data de término previsto.',
            default => 'Cada registro representa um estágio no recorte filtrado.',
        };
    }

    /**
     * Explicação em linguagem simples de como o indicador é calculado.
     *
     * @return array{summary: string, data_used: list<string>, how_calculated: list<string>, period_filter: string, notes: list<string>}
     */
    public function methodology(string $metric): array
    {
        return match ($metric) {
            'courses' => [
                'summary' => 'Mostra quantos estudantes diferentes realizaram estágio em cada curso.',
                'data_used' => [
                    'Todos os estágios visíveis para o seu vínculo que atendem aos filtros selecionados (curso, tipo, situação e período).',
                ],
                'how_calculated' => [
                    'O valor principal conta cada estudante apenas uma vez por curso, mesmo que tenha feito mais de um estágio no período.',
                    'Também exibimos o total de estágios ao lado para você comparar quando há estudantes com mais de um vínculo.',
                ],
                'period_filter' => 'Usa a data de início previsto do estágio.',
                'notes' => [
                    'Ao clicar em um curso, a lista detalhada mostra todos os estágios que compõem o total.',
                ],
            ],
            'advisors' => [
                'summary' => 'Mostra a quantidade de estágios sob orientação de cada docente.',
                'data_used' => [
                    'Todos os estágios do recorte filtrado, agrupados pelo docente orientador vinculado.',
                ],
                'how_calculated' => [
                    'Soma quantos estágios estão atribuídos a cada orientador, ordenando do maior para o menor volume.',
                ],
                'period_filter' => 'Usa a data de início previsto do estágio.',
                'notes' => [
                    'Estágios sem orientador vinculado aparecem agrupados como "Orientador não identificado".',
                ],
            ],
            'parties' => [
                'summary' => 'Identifica quais empresas ou instituições concedentes mais receberam estagiários.',
                'data_used' => [
                    'Todos os estágios do recorte filtrado, agrupados pela unidade concedente.',
                ],
                'how_calculated' => [
                    'Conta o total de estágios vinculados a cada concedente, do maior para o menor.',
                ],
                'period_filter' => 'Usa a data de início previsto do estágio.',
                'notes' => [
                    'Cada unidade cadastrada (como escolas ou filiais diferentes) é contabilizada separadamente.',
                ],
            ],
            'course_parties' => [
                'summary' => 'Mostra quantas concedentes diferentes receberam estagiários em cada curso.',
                'data_used' => [
                    'Todos os estágios do recorte filtrado, agrupados por curso.',
                ],
                'how_calculated' => [
                    'Conta cada concedente apenas uma vez por curso, mesmo que ela tenha recebido vários estudantes.',
                    'Ao lado, também exibimos o total de estágios daquele curso.',
                ],
                'period_filter' => 'Usa a data de início previsto do estágio.',
                'notes' => [
                    'Clique em um curso para ver quais concedentes e estudantes fazem parte do número.',
                ],
            ],
            'supervisors' => [
                'summary' => 'Lista os 5 supervisores de campo que mais acompanharam estudantes diferentes.',
                'data_used' => [
                    'Todos os estágios do recorte filtrado que possuem supervisor identificado.',
                ],
                'how_calculated' => [
                    'Conta quantos estudantes únicos cada supervisor acompanhou e exibe os 5 primeiros colocados.',
                    'Não calcula média por supervisor; mostra diretamente o volume de estudantes únicos e estágios.',
                ],
                'period_filter' => 'Usa a data de início previsto do estágio.',
                'notes' => [
                    'Estágios sem supervisor identificado não entram no ranking dos 5 primeiros.',
                ],
            ],
            'grade_distribution' => [
                'summary' => 'Distribui os estágios concluídos por faixas de nota final (de 0 a 10).',
                'data_used' => [
                    'Apenas estágios com situação "Concluído" dentro dos filtros de curso, tipo e período.',
                    'Utiliza a nota final consolidada do estágio (escala de 0 a 10).',
                ],
                'how_calculated' => [
                    'Cada estágio é colocado na faixa inteira da sua nota (por exemplo, nota 8,5 entra em "8 a <9"; nota 10 entra em "10").',
                    'Os cards do topo mostram a média geral, a maior nota, a menor nota e quantos concluídos já possuem nota.',
                ],
                'period_filter' => 'Usa a data de início previsto do estágio. O filtro de situação não se aplica porque este indicador considera sempre estágios concluídos.',
                'notes' => [
                    'Notas iguais a zero entram normalmente na média e na faixa "0 a <1".',
                    'Estágios concluídos ainda sem nota registrada aparecem separados na coluna "Sem nota" e não reduzem a média.',
                ],
            ],
            'grade_averages' => [
                'summary' => 'Compara a nota final média dos estágios concluídos entre os cursos.',
                'data_used' => [
                    'Apenas estágios com situação "Concluído" dentro dos filtros de curso, tipo e período.',
                    'Utiliza a nota final consolidada de cada estágio (escala de 0 a 10).',
                ],
                'how_calculated' => [
                    'Para cada curso, soma as notas finais disponíveis e divide pela quantidade de estágios com nota.',
                    'Os cards do topo mostram a média geral, a maior nota, a menor nota e o total de avaliados no recorte.',
                ],
                'period_filter' => 'Usa a data de início previsto do estágio. O filtro de situação não se aplica porque este indicador considera sempre estágios concluídos.',
                'notes' => [
                    'Notas iguais a zero entram no cálculo da média.',
                    'Estágios concluídos sem nota são informados ao lado ("sem nota") e não entram na divisão da média.',
                ],
            ],
            'statuses' => [
                'summary' => 'Apresenta um panorama geral de quantos estágios estão em cada situação atual.',
                'data_used' => [
                    'Todos os estágios do recorte filtrado (curso, tipo, situação e período).',
                ],
                'how_calculated' => [
                    'Agrupa os estágios pela situação atual (Em formalização, Aguardando assinaturas, Liberado, Em andamento, Pausado, Concluído, Cancelado etc.) e calcula o percentual de cada uma sobre o total.',
                ],
                'period_filter' => 'Usa a data de início previsto do estágio.',
                'notes' => [
                    'Clique em qualquer fatia da rosca ou linha da lista para ver os estágios daquela situação.',
                ],
            ],
            'cancellations' => [
                'summary' => 'Agrupa os estágios cancelados pelo motivo do cancelamento.',
                'data_used' => [
                    'Apenas estágios com situação "Cancelado" e seus pedidos de cancelamento aprovados.',
                ],
                'how_calculated' => [
                    'Usa o motivo consolidado pelo Setor de Estágio quando existir; caso contrário, usa a justificativa original do pedido (ignorando diferenças de maiúsculas/minúsculas e espaços extras).',
                    'Mostra a quantidade e o percentual de cada motivo sobre o total de cancelamentos.',
                ],
                'period_filter' => 'Usa a data efetiva do cancelamento. O filtro de situação não se aplica porque este indicador considera sempre estágios cancelados.',
                'notes' => [
                    'O Setor de Estágio pode marcar motivos parecidos e clicar em "Unificar motivo" para agrupá-los sem apagar a justificativa original do histórico.',
                ],
            ],
            'processing' => [
                'summary' => 'Mede quantos dias corridos, em média, um estágio leva desde a criação até ser liberado para execução.',
                'data_used' => [
                    'Estágios do recorte filtrado agrupados pelo mês em que foram criados no sistema.',
                ],
                'how_calculated' => [
                    'Para cada estágio já liberado, calcula a diferença exata em dias corridos entre a data de criação e a data de liberação.',
                    'No gráfico, cada mês mostra a média desses dias. Nos cards do topo, exibimos a média geral, a mediana (valor central), o menor e o maior tempo.',
                ],
                'period_filter' => 'Usa a data de criação do estágio no sistema.',
                'notes' => [
                    'Estágios que ainda não foram liberados aparecem contabilizados como "sem dados" e não entram no cálculo da média.',
                ],
            ],
            'addendum_processing' => [
                'summary' => 'Mede quantos dias corridos, em média, um termo aditivo leva desde o registro até ter a assinatura conferida pelo Setor.',
                'data_used' => [
                    'Documentos do tipo Aditivo (exceto aditivos cancelados) dos estágios no recorte filtrado, agrupados pelo mês de emissão/registro.',
                ],
                'how_calculated' => [
                    'Para cada aditivo conferido como assinado, calcula a diferença em dias corridos entre o registro do aditivo e a conferência da assinatura.',
                    'Cada mês apresenta a média de dias; os cards do topo trazem a média geral, mediana, menor e maior tempo.',
                ],
                'period_filter' => 'Usa a data de emissão ou registro do aditivo.',
                'notes' => [
                    'Aditivos que ainda aguardam assinatura aparecem como "sem dados" e não entram na média.',
                ],
            ],
            'release_deadline' => [
                'summary' => 'Verifica se a documentação inicial e eventuais aditivos de cada estágio foram liberados/conferidos dentro do prazo.',
                'data_used' => [
                    'Todos os estágios do recorte filtrado, junto com a data de liberação inicial, o início previsto e os aditivos assinados.',
                ],
                'how_calculated' => [
                    'No prazo: o estágio foi liberado até a data de início previsto E todos os aditivos assinados foram conferidos até a data de término previsto vigente antes do aditivo.',
                    'Fora do prazo: a liberação inicial passou do início previsto OU algum aditivo foi conferido depois do término previsto anterior.',
                    'Sem dados: estágios que ainda não foram liberados.',
                ],
                'period_filter' => 'Usa a data de início previsto do estágio.',
                'notes' => [
                    'Aditivos conferidos antes do término previsto vigente não são contados como atraso.',
                ],
            ],
            'completion_deadline' => [
                'summary' => 'Verifica se os estágios concluídos tiveram sua conclusão registrada até a data de término previsto.',
                'data_used' => [
                    'Apenas estágios com situação "Concluído" dentro dos filtros de curso, tipo e período.',
                ],
                'how_calculated' => [
                    'No prazo: a data em que o estágio foi marcado como concluído é igual ou anterior à data de término previsto.',
                    'Fora do prazo: a conclusão foi registrada após a data de término previsto.',
                    'Sem dados: estágios concluídos sem registro de data de conclusão no histórico.',
                ],
                'period_filter' => 'Usa a data de conclusão registrada (ou, na ausência dela, o término previsto). O filtro de situação não se aplica.',
                'notes' => [
                    'O gráfico de rosca mostra o consolidado geral e as barras abaixo detalham cada curso.',
                ],
            ],
            default => [
                'summary' => 'Resumo dos estágios no recorte selecionado.',
                'data_used' => ['Todos os estágios visíveis para o seu vínculo conforme os filtros aplicados.'],
                'how_calculated' => ['Contagem simples dos registros no recorte.'],
                'period_filter' => 'Usa a data de início previsto do estágio.',
                'notes' => [],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{total: int, in_progress: int, completed: int, addended: int, cancelled: int}
     */
    public function overview(Affiliation $affiliation, array $filters): array
    {
        $base = $this->filteredInternships($affiliation, $filters, includeStatusFilter: true);
        $this->applyPeriod($base, 'internships.planned_start_date', $filters['from'] ?? null, $filters['to'] ?? null);

        $counts = (clone $base)
            ->selectRaw('internships.status as status_key, COUNT(*) as total')
            ->groupBy('internships.status')
            ->pluck('total', 'status_key');

        $addended = (clone $base)
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('generated_documents')
                    ->whereColumn('generated_documents.internship_id', 'internships.id')
                    ->where('generated_documents.type', GeneratedDocumentType::Addendum->value)
                    ->where('generated_documents.status', GeneratedDocumentStatus::Signed->value);
            })
            ->count();

        $total = (int) $counts->sum();
        $inProgress = (int) $counts->get(InternshipStatus::InProgress->value, 0)
            + (int) $counts->get(InternshipStatus::Released->value, 0)
            + (int) $counts->get(InternshipStatus::Paused->value, 0);

        return [
            'total' => $total,
            'in_progress' => $inProgress,
            'completed' => (int) $counts->get(InternshipStatus::Completed->value, 0),
            'addended' => $addended,
            'cancelled' => (int) $counts->get(InternshipStatus::Cancelled->value, 0),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function dataset(Affiliation $affiliation, array $filters): Builder
    {
        $metric = (string) ($filters['metric'] ?? 'courses');
        abort_unless(array_key_exists($metric, $this->allowedMetrics($affiliation)), 403);

        $ignoreStatusFilter = in_array($metric, ['grade_distribution', 'grade_averages', 'cancellations', 'completion_deadline'], true);
        $stages = $this->filteredInternships($affiliation, $filters, includeStatusFilter: ! $ignoreStatusFilter)
            ->join('courses as report_course', 'report_course.id', '=', 'internships.course_id')
            ->select([
                'internships.id as internship_id',
                'report_course.name as course_name',
                'internships.status as status',
                'internships.planned_start_date',
                'internships.projected_end_date',
            ])
            ->selectRaw("internships.student_snapshot->>'name' as student_name");

        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        if (in_array($metric, ['courses', 'supervisors'], true)) {
            $stages->leftJoin('affiliations as report_student', 'report_student.id', '=', 'internships.student_affiliation_id')
                ->addSelect('report_student.user_id as student_id');
        }

        $date = match ($metric) {
            'processing' => 'internships.created_at',
            'addendum_processing' => 'report_document.created_at',
            'cancellations' => 'report_cancel.effective_date',
            'completion_deadline' => 'COALESCE(completions.completed_at::date, internships.projected_end_date)',
            default => 'internships.planned_start_date',
        };

        match ($metric) {
            'courses' => $stages->selectRaw('report_course.id::text as group_key, report_course.name as group_name'),
            'advisors' => $this->groupByParticipant($stages, 'advisor'),
            'supervisors' => $this->groupByParticipant($stages, 'supervisor'),
            'parties', 'course_parties' => $this->groupByParty($stages, $metric === 'course_parties'),
            'grade_distribution', 'grade_averages' => $this->grades($stages, $metric === 'grade_distribution'),
            'statuses' => $stages->selectRaw('internships.status as group_key, internships.status as group_name'),
            'cancellations' => $this->cancellations($stages),
            'processing' => $this->processing($stages, false),
            'addendum_processing' => $this->processing($stages, true),
            'release_deadline' => $this->releaseDeadline($stages),
            'completion_deadline' => $this->completionDeadline($stages),
            default => abort(404),
        };

        if ($metric === 'completion_deadline') {
            $stages->when($from, fn (EloquentBuilder $query) => $query->whereRaw("{$date} >= ?", [$from]))
                ->when($to, fn (EloquentBuilder $query) => $query->whereRaw("{$date} <= ?", [$to]));
        } else {
            $this->applyPeriod($stages, $date, $from, $to);
        }

        return DB::query()->fromSub($stages, 'records');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return EloquentBuilder<Internship>
     */
    private function filteredInternships(Affiliation $affiliation, array $filters, bool $includeStatusFilter): EloquentBuilder
    {
        return Internship::query()->visibleToAffiliation($affiliation)
            ->whereIn('internships.course_id', $this->courses($affiliation)->select('id'))
            ->when($filters['course'] ?? null, fn (EloquentBuilder $query, $id) => $query->where('internships.course_id', $id))
            ->when($filters['type'] ?? null, fn (EloquentBuilder $query, $id) => $query->where('internships.internship_type_id', $id))
            ->when($includeStatusFilter && filled($filters['status'] ?? null), fn (EloquentBuilder $query) => $query->where('internships.status', $filters['status']));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder|EloquentBuilder<TModel>  $query
     */
    private function applyPeriod(Builder|EloquentBuilder $query, string $column, ?string $from, ?string $to): void
    {
        $query->when($from, fn ($query) => $query->whereDate($column, '>=', $from))
            ->when($to, fn ($query) => $query->whereDate($column, '<=', $to));
    }

    /** @param EloquentBuilder<Internship> $stages */
    private function groupByParticipant(EloquentBuilder $stages, string $role): void
    {
        $fallback = $role === 'advisor' ? 'Orientador não identificado' : 'Supervisor não identificado';

        $stages->leftJoin('affiliations as participant', 'participant.id', '=', "internships.{$role}_affiliation_id")
            ->leftJoin('users as participant_user', 'participant_user.id', '=', 'participant.user_id')
            ->selectRaw("COALESCE(participant_user.id::text, 'missing') as group_key, COALESCE(participant_user.name, ?) as group_name", [$fallback]);
    }

    /** @param EloquentBuilder<Internship> $stages */
    private function groupByParty(EloquentBuilder $stages, bool $byCourse): void
    {
        $stages->leftJoin('granting_parties as report_party', 'report_party.id', '=', 'internships.granting_party_id')
            ->addSelect('report_party.id as party_id', 'report_party.name as party_name')
            ->selectRaw($byCourse
                ? 'report_course.id::text as group_key, report_course.name as group_name'
                : "COALESCE(report_party.id::text, 'missing') as group_key,
                    COALESCE(report_party.name, 'Concedente não identificada') as group_name");
    }

    /** @param EloquentBuilder<Internship> $stages */
    private function grades(EloquentBuilder $stages, bool $distribution): void
    {
        $stages->where('internships.status', InternshipStatus::Completed)
            ->addSelect('internships.consolidated_grade as grade')
            ->selectRaw($distribution
                ? "COALESCE(FLOOR(internships.consolidated_grade)::text, 'missing') as group_key,
                    COALESCE(FLOOR(internships.consolidated_grade)::text, 'missing') as group_name"
                : 'report_course.id::text as group_key, report_course.name as group_name');
    }

    private function signatureChecks(?Builder $documentIds = null): Builder
    {
        return Activity::query()->where('subject_type', (new GeneratedDocument)->getMorphClass())
            ->where('event', 'updated')
            ->when($documentIds !== null, fn (EloquentBuilder $query) => $query->whereIn('subject_id', $documentIds))
            ->where('attribute_changes->attributes->status', GeneratedDocumentStatus::Signed->value)
            ->whereIn('attribute_changes->old->status', [GeneratedDocumentStatus::Generated->value, GeneratedDocumentStatus::AwaitingSignature->value])
            ->groupBy('subject_id')->selectRaw('subject_id as document_id, MIN(created_at) as checked_at')->toBase();
    }

    /** @param EloquentBuilder<Internship>|null $stages */
    private function addendumDeadlines(?EloquentBuilder $stages = null): Builder
    {
        $stageIds = $stages === null ? null : (clone $stages)->select('internships.id')->toBase();
        $documentIds = $stageIds === null ? null : GeneratedDocument::query()
            ->where('generated_documents.type', GeneratedDocumentType::Addendum)
            ->where('generated_documents.status', GeneratedDocumentStatus::Signed)
            ->whereIn('generated_documents.internship_id', $stageIds)
            ->select('generated_documents.id')
            ->toBase();

        $externalRegistrations = Activity::query()
            ->where('subject_type', (new GeneratedDocument)->getMorphClass())
            ->where('event', 'external_document_registered')
            ->when($documentIds !== null, fn (EloquentBuilder $query) => $query->whereIn('subject_id', $documentIds))
            ->selectRaw("subject_id as document_id,
                MAX(properties->'work_schedule_change'->>'previous_projected_end_date') as ext_prev_end,
                MAX(properties->'work_schedule_change'->>'starts_on') as ext_starts_on")
            ->groupBy('subject_id')
            ->toBase();

        $internshipEndChanges = Activity::query()
            ->where('subject_type', (new Internship)->getMorphClass())
            ->where('event', 'updated')
            ->when($stageIds !== null, fn (EloquentBuilder $query) => $query->whereIn('subject_id', $stageIds))
            ->whereRaw("NULLIF(attribute_changes->'old'->>'projected_end_date', '') IS NOT NULL")
            ->selectRaw("subject_id as internship_id, created_at as changed_at,
                SUBSTRING(attribute_changes->'old'->>'projected_end_date' FROM 1 FOR 10) as old_end_date")
            ->toBase();

        $referenceExpr = "COALESCE(
            NULLIF(SUBSTRING(generated_documents.snapshot->'work_schedule_change'->>'previous_projected_end_date' FROM 1 FOR 10), ''),
            NULLIF(SUBSTRING(ext_reg.ext_prev_end FROM 1 FOR 10), ''),
            (SELECT iec.old_end_date FROM ({$internshipEndChanges->toSql()}) as iec
                WHERE iec.internship_id = generated_documents.internship_id
                AND checks.checked_at IS NOT NULL
                AND ABS(EXTRACT(EPOCH FROM (iec.changed_at - checks.checked_at))) <= 60
                ORDER BY ABS(EXTRACT(EPOCH FROM (iec.changed_at - checks.checked_at))) ASC
                LIMIT 1),
            NULLIF(SUBSTRING(generated_documents.snapshot->'work_schedule_change'->>'starts_on' FROM 1 FOR 10), ''),
            NULLIF(SUBSTRING(ext_reg.ext_starts_on FROM 1 FOR 10), '')
        )::date";

        return GeneratedDocument::query()
            ->where('generated_documents.type', GeneratedDocumentType::Addendum)
            ->where('generated_documents.status', GeneratedDocumentStatus::Signed)
            ->when($stageIds !== null, fn (EloquentBuilder $query) => $query->whereIn('generated_documents.internship_id', $stageIds))
            ->leftJoinSub($this->signatureChecks($documentIds), 'checks', 'checks.document_id', '=', 'generated_documents.id')
            ->leftJoinSub($externalRegistrations, 'ext_reg', 'ext_reg.document_id', '=', 'generated_documents.id')
            ->join('internships as addendum_stage', 'addendum_stage.id', '=', 'generated_documents.internship_id')
            ->groupBy('generated_documents.internship_id')
            ->selectRaw("generated_documents.internship_id,
                COUNT(*) as addendum_count,
                MAX(checks.checked_at) as last_addendum_check,
                COUNT(*) FILTER (
                    WHERE checks.checked_at IS NOT NULL
                    AND checks.checked_at::date > COALESCE({$referenceExpr}, addendum_stage.projected_end_date)
                ) as late_addenda_count", $internshipEndChanges->getBindings())
            ->toBase();
    }

    /** @param EloquentBuilder<Internship> $stages */
    private function releaseDeadline(EloquentBuilder $stages): void
    {
        $addenda = $this->addendumDeadlines($stages);

        $stages->leftJoinSub($addenda, 'addendum_deadlines', 'addendum_deadlines.internship_id', '=', 'internships.id')
            ->selectRaw("report_course.id::text as group_key,
                report_course.name as group_name,
                internships.released_at,
                COALESCE(addendum_deadlines.addendum_count, 0) as addendum_count,
                COALESCE(addendum_deadlines.late_addenda_count, 0) as late_addenda_count,
                CASE
                    WHEN internships.released_at IS NULL OR internships.planned_start_date IS NULL THEN 'unknown'
                    WHEN internships.released_at::date <= internships.planned_start_date
                        AND COALESCE(addendum_deadlines.late_addenda_count, 0) = 0 THEN 'on_time'
                    ELSE 'late'
                END as deadline");
    }

    /** @param EloquentBuilder<Internship> $stages */
    private function completionDeadline(EloquentBuilder $stages): void
    {
        $stages->where('internships.status', InternshipStatus::Completed);
        $stageIds = (clone $stages)->select('internships.id')->toBase();

        $completions = Activity::query()
            ->where('subject_type', (new Internship)->getMorphClass())
            ->where('event', 'updated')
            ->whereIn('subject_id', $stageIds)
            ->where('attribute_changes->attributes->status', InternshipStatus::Completed->value)
            ->groupBy('subject_id')
            ->selectRaw('subject_id as internship_id, MAX(created_at) as completed_at')
            ->toBase();

        $stages->leftJoinSub($completions, 'completions', 'completions.internship_id', '=', 'internships.id')
            ->selectRaw("report_course.id::text as group_key,
                report_course.name as group_name,
                completions.completed_at,
                CASE
                    WHEN completions.completed_at IS NULL OR internships.projected_end_date IS NULL THEN 'unknown'
                    WHEN completions.completed_at::date <= internships.projected_end_date THEN 'on_time'
                    ELSE 'late'
                END as deadline");
    }

    /** @param EloquentBuilder<Internship> $stages */
    private function cancellations(EloquentBuilder $stages): void
    {
        $decision = InternshipCancellationRequest::query()->where('status', InternshipCancellationRequestStatus::Approved)
            ->select(['id as cancellation_request_id', 'internship_id', 'reason', 'report_reason', 'decision_reason', 'effective_date'])
            ->distinct('internship_id')->orderBy('internship_id')->orderByDesc('id');

        $effectiveReason = "NULLIF(BTRIM(REGEXP_REPLACE(COALESCE(NULLIF(BTRIM(report_cancel.report_reason), ''), report_cancel.reason), '\s+', ' ', 'g')), '')";

        $stages->where('internships.status', InternshipStatus::Cancelled)
            ->leftJoinSub($decision, 'report_cancel', 'report_cancel.internship_id', '=', 'internships.id')
            ->addSelect(
                'report_cancel.cancellation_request_id',
                'report_cancel.reason',
                'report_cancel.report_reason',
                'report_cancel.decision_reason',
                'report_cancel.effective_date',
            )
            ->selectRaw("COALESCE(MD5(LOWER({$effectiveReason})), 'missing') as group_key,
                COALESCE({$effectiveReason}, 'Sem justificativa registrada') as group_name");
    }

    /** @param EloquentBuilder<Internship> $stages */
    private function processing(EloquentBuilder $stages, bool $addendum): void
    {
        if ($addendum) {
            $stages->join('generated_documents as report_document', 'report_document.internship_id', '=', 'internships.id')
                ->where('report_document.type', GeneratedDocumentType::Addendum)
                ->where('report_document.status', '!=', GeneratedDocumentStatus::Cancelled)
                ->leftJoinSub($this->signatureChecks(), 'checks', 'checks.document_id', '=', 'report_document.id')
                ->addSelect('report_document.id as document_id');
        }

        $start = $addendum ? 'report_document.created_at' : 'internships.created_at';
        $end = $addendum ? 'checks.checked_at' : 'internships.released_at';
        $stages->selectRaw("{$start} as started_at, {$end} as finished_at,
            COALESCE(TO_CHAR({$start}, 'YYYY-MM'), 'missing') as group_key,
            COALESCE(TO_CHAR({$start}, 'YYYY-MM'), 'missing') as group_name,
            CASE WHEN {$end} >= {$start} THEN EXTRACT(EPOCH FROM ({$end} - {$start})) / 86400 END as days");
    }

    public function details(Builder $dataset, string $metric, string $group): Builder
    {
        return (clone $dataset)->when($group !== '', fn (Builder $query) => $query->where('group_key', $group))
            ->orderBy('group_key')->orderBy('internship_id')
            ->when($metric === 'addendum_processing', fn (Builder $query) => $query->orderBy('document_id'));
    }

    /** @return Collection<int, stdClass> */
    public function summary(Builder $dataset, string $metric): Collection
    {
        $groupNameExpr = $metric === 'cancellations' ? 'MIN(group_name) as group_name' : 'group_name';
        $summary = (clone $dataset)->selectRaw("group_key, {$groupNameExpr}, COUNT(*) as total");
        if ($metric === 'cancellations') {
            $summary->groupBy('group_key');
        } else {
            $summary->groupBy('group_key', 'group_name');
        }

        match ($metric) {
            'courses', 'supervisors' => $summary->selectRaw('COUNT(DISTINCT student_id) as value')->orderByDesc('value'),
            'course_parties' => $summary->selectRaw('COUNT(DISTINCT party_id) as value')->orderByDesc('value'),
            'grade_averages' => $summary->selectRaw('ROUND(AVG(grade), 2) as value, COUNT(grade) as measured')->orderByRaw('value DESC NULLS LAST'),
            'processing', 'addendum_processing' => $summary->selectRaw('ROUND(AVG(days), 2) as value, COUNT(days) as measured')->orderBy('group_key'),
            'grade_distribution' => $summary->orderByRaw("CASE WHEN group_key = 'missing' THEN 11 ELSE group_key::int END"),
            'release_deadline', 'completion_deadline' => $this->deadlineCounts($summary)->orderBy('group_name'),
            default => $summary->orderByDesc('total'),
        };

        return $summary->orderBy('group_name')->orderBy('group_key')->when($metric === 'supervisors',
            fn (Builder $query) => $query->where('group_key', '!=', 'missing')->limit(5))->get();
    }

    public function deadlineCounts(Builder $dataset): Builder
    {
        return $dataset->selectRaw("COUNT(*) FILTER (WHERE deadline = 'on_time') as on_time,
            COUNT(*) FILTER (WHERE deadline = 'late') as late,
            COUNT(*) FILTER (WHERE deadline = 'unknown') as unknown");
    }

    /**
     * @return array{avg: ?float, min: ?float, max: ?float, measured: int, missing: int, total: int}
     */
    public function gradeStats(Builder $dataset): array
    {
        $row = (clone $dataset)->selectRaw('
            COUNT(*) as total,
            COUNT(grade) as measured,
            ROUND(AVG(grade), 2) as avg_grade,
            ROUND(MIN(grade), 2) as min_grade,
            ROUND(MAX(grade), 2) as max_grade
        ')->first();

        $total = (int) ($row->total ?? 0);
        $measured = (int) ($row->measured ?? 0);

        return [
            'avg' => $row?->avg_grade !== null ? (float) $row->avg_grade : null,
            'min' => $row?->min_grade !== null ? (float) $row->min_grade : null,
            'max' => $row?->max_grade !== null ? (float) $row->max_grade : null,
            'measured' => $measured,
            'missing' => $total - $measured,
            'total' => $total,
        ];
    }

    /**
     * @return array{avg: ?float, median: ?float, min: ?float, max: ?float, measured: int, missing: int, total: int}
     */
    public function processingStats(Builder $dataset): array
    {
        $row = (clone $dataset)->selectRaw('
            COUNT(*) as total,
            COUNT(days) as measured,
            ROUND(AVG(days)::numeric, 2) as avg_days,
            ROUND((PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY days))::numeric, 2) as median_days,
            ROUND(MIN(days)::numeric, 2) as min_days,
            ROUND(MAX(days)::numeric, 2) as max_days
        ')->first();

        $total = (int) ($row->total ?? 0);
        $measured = (int) ($row->measured ?? 0);

        return [
            'avg' => $row?->avg_days !== null ? (float) $row->avg_days : null,
            'median' => $row?->median_days !== null ? (float) $row->median_days : null,
            'min' => $row?->min_days !== null ? (float) $row->min_days : null,
            'max' => $row?->max_days !== null ? (float) $row->max_days : null,
            'measured' => $measured,
            'missing' => $total - $measured,
            'total' => $total,
        ];
    }

    public function groupLabel(string $name, string $metric): string
    {
        return match ($metric) {
            'statuses' => InternshipStatus::from($name)->label(),
            'processing', 'addendum_processing' => $name === 'missing' ? 'Sem data' : formatMonthYear($name.'-01'),
            'grade_distribution' => match ($name) {
                'missing' => 'Sem nota',
                '10' => '10',
                default => $name.' a <'.((int) $name + 1),
            },
            default => $name,
        };
    }

    public function deadlineLabel(string $status): string
    {
        return match ($status) {
            'on_time' => 'No prazo',
            'late' => 'Fora do prazo',
            default => 'Sem dados',
        };
    }

    /** @return list<string> */
    public function summaryHeadings(string $metric): array
    {
        return match ($metric) {
            'courses', 'supervisors' => ['Grupo', 'Estudantes únicos', 'Estágios'],
            'course_parties' => ['Curso', 'Concedentes únicas', 'Estágios'],
            'grade_averages' => ['Curso', 'Média final', 'Com nota', 'Sem nota'],
            'processing', 'addendum_processing' => ['Mês de referência', 'Média (dias)', 'Com dados', 'Sem dados'],
            'release_deadline', 'completion_deadline' => ['Curso', 'No prazo', 'Fora do prazo', 'Sem dados', 'Total'],
            default => ['Grupo', 'Total de estágios'],
        };
    }

    /** @return list<string|int|float|null> */
    public function summaryCells(stdClass $item, string $metric): array
    {
        $label = $this->groupLabel((string) $item->group_name, $metric);

        return match ($metric) {
            'courses', 'supervisors', 'course_parties' => [$label, (int) $item->value, (int) $item->total],
            'grade_averages', 'processing', 'addendum_processing' => [
                $label,
                $item->value === null ? '' : number_format((float) $item->value, 2, '.', ''),
                (int) $item->measured,
                (int) $item->total - (int) $item->measured,
            ],
            'release_deadline', 'completion_deadline' => [
                $label,
                (int) $item->on_time,
                (int) $item->late,
                (int) $item->unknown,
                (int) $item->total,
            ],
            default => [$label, (int) $item->total],
        };
    }

    /** @return list<string> */
    public function headings(string $metric): array
    {
        return ['Estágio', 'Discente', 'Curso', 'Situação', 'Início previsto', 'Término previsto', ...match ($metric) {
            'advisors' => ['Orientador'],
            'parties', 'course_parties' => ['Concedente'],
            'supervisors' => ['Supervisor'],
            'grade_distribution', 'grade_averages' => ['Nota final'],
            'cancellations' => ['Cancelamento efetivo', 'Motivo agrupado', 'Justificativa original', 'Justificativa da decisão'],
            'processing' => ['Criação do estágio', 'Liberação', 'Dias corridos'],
            'addendum_processing' => ['Aditivo', 'Emissão ou registro', 'Conferência', 'Dias corridos'],
            'release_deadline' => ['Liberação', 'Aditivos conferidos', 'Aditivos fora do prazo', 'Situação do prazo'],
            'completion_deadline' => ['Conclusão registrada', 'Situação do prazo'],
            default => [],
        }];
    }

    /** @return list<string|int|null> */
    public function cells(stdClass $row, string $metric): array
    {
        return [
            $row->internship_id,
            $row->student_name,
            $row->course_name,
            InternshipStatus::from($row->status)->label(),
            $row->planned_start_date === null ? null : formatShort($row->planned_start_date),
            $row->projected_end_date === null ? null : formatShort($row->projected_end_date),
            ...match ($metric) {
                'advisors', 'supervisors' => [$row->group_name],
                'parties', 'course_parties' => [$row->party_name],
                'grade_distribution', 'grade_averages' => [$row->grade],
                'cancellations' => [
                    $row->effective_date === null ? null : formatShort($row->effective_date),
                    $row->group_name,
                    $row->reason,
                    $row->decision_reason,
                ],
                'processing', 'addendum_processing' => [
                    ...($metric === 'addendum_processing' ? [$row->document_id] : []),
                    $row->started_at === null ? null : formatDateTime($row->started_at),
                    $row->finished_at === null ? null : formatDateTime($row->finished_at),
                    $row->days === null ? null : number_format((float) $row->days, 2, '.', ''),
                ],
                'release_deadline' => [
                    $row->released_at === null ? null : formatDateTime($row->released_at),
                    (int) ($row->addendum_count ?? 0),
                    (int) ($row->late_addenda_count ?? 0),
                    $this->deadlineLabel($row->deadline),
                ],
                'completion_deadline' => [
                    $row->completed_at === null ? null : formatDateTime($row->completed_at),
                    $this->deadlineLabel($row->deadline),
                ],
                default => [],
            },
        ];
    }
}
