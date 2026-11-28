<?php

namespace App\Support;

use App\Models\Campus;
use App\Models\Course;
use App\Models\DocumentTemplate;
use App\Models\EmailDeliveryAttempt;
use App\Models\GrantingParty;
use App\Models\Holiday;
use App\Models\HolidayRecurrence;
use App\Models\Internship;
use App\Models\InternshipRequest;
use App\Models\InternshipType;
use App\Models\SupervisorEvaluation;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

final class SectorCommandPaletteItems
{
    public function __construct(private ActiveAffiliationContext $affiliationContext) {}

    /**
     * @return list<array{id: string, group: string, title: string, keywords: list<string>, href: string, icon: string, download: bool}>
     */
    public function forUser(User $user): array
    {
        $items = [];

        $items[] = $this->item(
            'page-dashboard',
            'Páginas',
            'Painel',
            ['início', 'home', 'resumo', 'dashboard', 'principal', 'visão geral', 'pendências'],
            route('dashboard'),
            'home',
        );
        $items[] = $this->item(
            'page-notifications',
            'Páginas',
            'Notificações',
            ['avisos', 'alertas', 'mensagens', 'sino', 'notificação', 'pendências'],
            route('notifications.index'),
            'bell',
        );

        if ($user->can('viewReviewList', InternshipRequest::class)) {
            $items[] = $this->item(
                'page-requests',
                'Páginas',
                'Solicitações de estágio',
                ['pedidos', 'solicitação', 'revisão', 'análise', 'aprovar', 'deferir', 'indeferir', 'fila', 'pendentes', 'aluno', 'discente', 'estudante', 'requerimento'],
                route('internship-review.index'),
                'clipboard-document-check',
            );
        }

        if ($user->can('viewAny', Internship::class)) {
            $items[] = $this->item(
                'page-internships',
                'Páginas',
                'Estágios',
                ['estágio', 'acompanhamento', 'termos', 'tce', 'aditivo', 'rescisão', 'encerramento', 'relatório', 'contrato', 'aluno', 'discente', 'estudante', 'ativo', 'andamento'],
                route('internships.index'),
                'briefcase',
            );
        }

        if ($user->can('viewAny', SupervisorEvaluation::class)) {
            $items[] = $this->item(
                'page-evaluations',
                'Páginas',
                'Avaliações do supervisor',
                ['avaliação', 'avaliações', 'supervisor', 'empresa', 'orientador', 'notas', 'conceitos', 'parecer', 'desempenho', 'ficha'],
                route('supervisor-evaluations.index'),
                'clipboard-document-check',
            );
        }

        if ($user->can('viewIndicators', Internship::class)) {
            $items[] = $this->item(
                'page-indicators',
                'Páginas',
                'Indicadores',
                ['gráficos', 'relatórios', 'estatísticas', 'métricas', 'dados', 'painel', 'números', 'dashboard', 'kpi'],
                route('internship-indicators.index'),
                'chart-bar',
            );
        }

        if ($user->can('viewOwn', Campus::class)) {
            $items[] = $this->item(
                'page-campus',
                'Páginas',
                'Meu campus',
                ['unidade', 'instituição', 'campus', 'cnpj', 'endereço', 'dados', 'diretor', 'representante'],
                route('campuses.own'),
                'building-office-2',
            );
        }

        if ($user->can('viewAny', User::class)) {
            $items[] = $this->item(
                'page-users',
                'Páginas',
                'Usuários',
                ['usuário', 'pessoas', 'vínculos', 'contas', 'cadastros', 'alunos', 'discentes', 'estudantes', 'professores', 'orientadores', 'coordenadores', 'servidores', 'acesso'],
                route('users.index'),
                'users',
            );
        }

        if ($user->can('viewAny', Course::class)) {
            $items[] = $this->item(
                'page-courses',
                'Páginas',
                'Cursos',
                ['curso', 'graduação', 'técnico', 'superior', 'integrado', 'subsequente', 'pós', 'coordenação', 'coordenador'],
                route('courses.index'),
                'academic-cap',
            );
        }

        if ($user->can('viewAny', InternshipType::class)) {
            $items[] = $this->item(
                'page-types',
                'Páginas',
                'Tipos de estágio',
                ['tipo', 'modalidade', 'obrigatório', 'não obrigatório', 'carga horária', 'horas', 'requisitos'],
                route('internship-types.index'),
                'briefcase',
            );
        }

        if ($user->can('viewAny', GrantingParty::class)) {
            $items[] = $this->item(
                'page-parties',
                'Páginas',
                'Partes concedentes',
                ['concedente', 'concedentes', 'empresas', 'empresa', 'organização', 'órgão', 'instituição', 'convênios', 'convênio', 'cnpj', 'cpf', 'parceiro', 'local', 'locais'],
                route('granting-parties.index'),
                'building-office',
            );
        }

        if ($user->can('viewAny', DocumentTemplate::class)) {
            $items[] = $this->item(
                'page-templates',
                'Páginas',
                'Templates DOCX',
                ['template', 'modelos', 'modelo', 'documentos', 'documento', 'word', 'docx', 'tce', 'termo', 'aditivo', 'rescisão', 'plano atividades', 'declaração'],
                route('document-templates.index'),
                'document-text',
            );
            $items[] = $this->item(
                'page-templates-guide',
                'Páginas',
                'Guia de variáveis (Templates DOCX)',
                ['guia', 'ajuda', 'documentação', 'placeholders', 'variáveis', 'marcadores', 'tags', 'campos', 'templates', 'modelos', 'word', 'docx'],
                route('document-templates.guide'),
                'book-open',
            );
        }

        if ($user->can('viewAny', Holiday::class)) {
            $items[] = $this->item(
                'page-holidays',
                'Páginas',
                'Feriados',
                ['feriado', 'calendário', 'recesso', 'ponto facultativo', 'datas', 'dias não letivos'],
                route('holidays.index'),
                'calendar',
            );
            $items[] = $this->item(
                'page-holidays-trash',
                'Páginas',
                'Lixeira de feriados',
                ['lixeira', 'excluídos', 'apagados', 'removidos', 'restaurar', 'recuperar', 'feriado', 'calendário'],
                route('holidays.trash'),
                'trash',
            );
            $items[] = $this->item(
                'page-holiday-requests',
                'Páginas',
                'Solicitações de feriado',
                ['solicitação', 'pedidos', 'aprovação', 'revisão', 'feriado', 'calendário', 'pendentes'],
                route('holiday-requests.index'),
                'calendar-days',
            );
        }

        if ($user->can('viewAny', HolidayRecurrence::class)) {
            $items[] = $this->item(
                'page-holiday-recurrences',
                'Páginas',
                'Feriados recorrentes',
                ['feriado', 'calendário', 'recorrência', 'anual', 'fixo', 'móvel', 'páscoa', 'carnaval', 'repetição'],
                route('holiday-recurrences.index'),
                'arrow-path',
            );
            $items[] = $this->item(
                'page-holiday-recurrences-trash',
                'Páginas',
                'Lixeira de feriados recorrentes',
                ['lixeira', 'excluídos', 'apagados', 'removidos', 'restaurar', 'recuperar', 'feriado recorrente', 'calendário'],
                route('holiday-recurrences.trash'),
                'trash',
            );
        }

        if ($user->can('manage-backups')) {
            $items[] = $this->item(
                'page-backups',
                'Páginas',
                'Backups',
                ['backup', 'cópia', 'segurança', 'restaurar', 'banco', 'arquivos', 'zip', 'download'],
                route('backups.index'),
                'archive-box',
            );
        }

        if ($user->can('viewAny', Activity::class)) {
            $items[] = $this->item(
                'page-audit',
                'Páginas',
                'Auditoria',
                ['logs', 'log', 'histórico', 'atividades', 'registro', 'rastreamento', 'alterações', 'eventos'],
                route('audit.index'),
                'clipboard-document-list',
            );
        }

        if ($user->can('viewAny', EmailDeliveryAttempt::class)) {
            $items[] = $this->item(
                'page-emails',
                'Páginas',
                'E-mails',
                ['email', 'emails', 'correio', 'logs', 'envios', 'disparos', 'mensagens', 'falhas', 'entrega'],
                route('email-logs.index'),
                'envelope',
            );
        }

        $items[] = $this->item(
            'page-profile',
            'Páginas',
            'Configurações da conta',
            ['perfil', 'conta', 'dados pessoais', 'nome', 'email', 'configurações', 'preferências'],
            route('profile.edit'),
            'cog-6-tooth',
        );
        $items[] = $this->item(
            'page-appearance',
            'Páginas',
            'Aparência',
            ['tema', 'escuro', 'claro', 'sistema', 'dark', 'light', 'mode', 'visual', 'cores', 'interface'],
            route('appearance.edit'),
            'sun',
        );
        $items[] = $this->item(
            'page-security',
            'Páginas',
            'Segurança',
            ['senha', 'alterar senha', 'trocar senha', '2fa', 'autenticação', 'dois fatores', 'totp', 'recuperação', 'proteção'],
            route('security.edit'),
            'shield-check',
        );

        if ($user->can('viewOwn', Campus::class)) {
            $items[] = $this->item(
                'action-edit-campus',
                'Ações rápidas',
                'Editar meu campus',
                ['editar', 'alterar', 'atualizar', 'campus', 'unidade', 'cnpj', 'representante', 'diretor', 'endereço', 'telefone'],
                route('campuses.own.edit'),
                'pencil-square',
            );
        }

        if ($user->can('create', User::class)) {
            $items[] = $this->item(
                'action-new-user',
                'Ações rápidas',
                'Novo usuário',
                ['cadastrar', 'criar', 'adicionar', 'incluir', 'novo usuário', 'pessoa', 'aluno', 'discente', 'professor', 'orientador', 'servidor', 'conta'],
                route('users.create'),
                'plus',
            );
        }

        if ($user->can('import', User::class)) {
            $items[] = $this->item(
                'action-import-users',
                'Ações rápidas',
                'Importar usuários (CSV)',
                ['csv', 'planilha', 'excel', 'tabela', 'lote', 'massa', 'importar', 'subir', 'upload', 'usuários', 'alunos', 'discentes'],
                route('users.import'),
                'arrow-up-tray',
            );
        }

        if ($user->can('create', Course::class)) {
            $items[] = $this->item(
                'action-new-course',
                'Ações rápidas',
                'Novo curso',
                ['cadastrar', 'criar', 'adicionar', 'incluir', 'novo curso', 'graduação', 'técnico', 'coordenação'],
                route('courses.create'),
                'plus',
            );
        }

        if ($user->can('create', InternshipType::class)) {
            $items[] = $this->item(
                'action-new-type',
                'Ações rápidas',
                'Novo tipo de estágio',
                ['cadastrar', 'criar', 'adicionar', 'incluir', 'novo tipo', 'modalidade', 'estágio', 'obrigatório', 'horas'],
                route('internship-types.create'),
                'plus',
            );
        }

        if ($user->can('create', GrantingParty::class)) {
            $items[] = $this->item(
                'action-new-party',
                'Ações rápidas',
                'Nova parte concedente',
                ['cadastrar', 'criar', 'adicionar', 'incluir', 'nova empresa', 'empresas', 'concedente', 'cnpj', 'cpf', 'convênio', 'parceiro'],
                route('granting-parties.create'),
                'plus',
            );
            $items[] = $this->item(
                'action-import-parties',
                'Ações rápidas',
                'Importar partes concedentes (CSV)',
                ['empresas', 'empresa', 'concedente', 'concedentes', 'csv', 'planilha', 'excel', 'lote', 'massa', 'importar', 'subir', 'upload', 'cnpj'],
                route('granting-parties.import'),
                'arrow-up-tray',
            );
        }

        if ($user->can('create', DocumentTemplate::class)) {
            $items[] = $this->item(
                'action-new-template',
                'Ações rápidas',
                'Novo template DOCX',
                ['cadastrar', 'criar', 'adicionar', 'subir', 'upload', 'novo template', 'modelo', 'documento', 'word', 'docx', 'tce'],
                route('document-templates.create'),
                'plus',
            );
        }

        if ($user->can('create', Holiday::class)) {
            $items[] = $this->item(
                'action-new-holiday',
                'Ações rápidas',
                'Novo feriado',
                ['cadastrar', 'criar', 'adicionar', 'incluir', 'novo feriado', 'data', 'calendário', 'recesso'],
                route('holidays.create'),
                'plus',
            );
        }

        if ($user->can('create', HolidayRecurrence::class)) {
            $items[] = $this->item(
                'action-new-holiday-recurrence',
                'Ações rápidas',
                'Novo feriado recorrente',
                ['cadastrar', 'criar', 'adicionar', 'incluir', 'novo feriado recorrente', 'anual', 'calendário'],
                route('holiday-recurrences.create'),
                'plus',
            );
        }

        if ($user->can('exportCsv', Internship::class)) {
            $items[] = $this->item(
                'action-export-internships',
                'Ações rápidas',
                'Exportar estágios (CSV)',
                ['exportar', 'csv', 'planilha', 'excel', 'relatório', 'baixar', 'download', 'estágios'],
                route('internships.export'),
                'arrow-down-tray',
                true,
            );
        }

        if ($user->can('viewIndicators', Internship::class)) {
            $items[] = $this->item(
                'action-export-indicators',
                'Ações rápidas',
                'Exportar indicadores (CSV)',
                ['exportar', 'csv', 'planilha', 'excel', 'relatório', 'baixar', 'download', 'indicadores', 'estatísticas', 'gráficos'],
                route('internship-indicators.export'),
                'arrow-down-tray',
                true,
            );
        }

        if ($user->can('viewAny', GrantingParty::class)) {
            $items[] = $this->item(
                'action-export-parties',
                'Ações rápidas',
                'Exportar partes concedentes (CSV)',
                ['exportar', 'empresas', 'empresa', 'concedente', 'concedentes', 'csv', 'planilha', 'excel', 'relatório', 'baixar', 'download'],
                route('granting-parties.export'),
                'arrow-down-tray',
                true,
            );
        }

        if ($this->affiliationContext->availableFor($user)->count() > 1) {
            $items[] = $this->item(
                'action-switch-affiliation',
                'Ações rápidas',
                'Trocar vínculo',
                ['trocar', 'mudar', 'alternar', 'vínculo', 'perfil', 'contexto', 'papel', 'campus', 'setor'],
                route('affiliations.select'),
                'arrows-right-left',
            );
        }

        return $items;
    }

    /**
     * @param  list<string>  $keywords
     * @return array{id: string, group: string, title: string, keywords: list<string>, href: string, icon: string, download: bool}
     */
    private function item(
        string $id,
        string $group,
        string $title,
        array $keywords,
        string $href,
        string $icon,
        bool $download = false,
    ): array {
        return [
            'id' => $id,
            'group' => $group,
            'title' => $title,
            'keywords' => $keywords,
            'href' => $href,
            'icon' => $icon,
            'download' => $download,
        ];
    }
}
