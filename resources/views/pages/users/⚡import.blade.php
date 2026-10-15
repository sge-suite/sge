<?php

use App\Actions\PrepareUserImport;
use App\Actions\ProcessUserImport;
use App\Models\Course;
use App\Concerns\InteractsWithCsvImport;
use App\Models\User;
use App\Support\ActiveAffiliationContext;
use App\Support\UserCsv;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Importar usuários e vínculos')] class extends Component
{
    use InteractsWithCsvImport;

    #[Locked]
    public ?int $activeAffiliationId = null;

    /** @var list<int|string> */
    public array $historyApprovals = [];

    public function boot(): void
    {
        Gate::authorize('import', User::class);
        $selected = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'));
        abort_unless($this->activeAffiliationId === null || $this->activeAffiliationId === $selected?->id, 403);
        if ($this->import !== []) {
            app(ProcessUserImport::class)->authorize(auth()->user(), $this->import);
        }
    }

    public function mount(): void
    {
        $this->activeAffiliationId = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'))->id;
    }

    public function analyze(PrepareUserImport $prepare): void
    {
        Gate::authorize('import', User::class);
        $this->analyzeCsv(fn (string $contents, string $separator): array => $prepare->handle(auth()->user(), $contents, $separator));
        $this->historyApprovals = [];
    }

    public function confirm(ProcessUserImport $process): void
    {
        $this->validate([
            'historyApprovals' => ['array', 'max:500'],
            'historyApprovals.*' => ['integer', 'min:0', 'max:499'],
        ]);
        $this->import = $process->confirm(auth()->user(), $this->import, $this->historyApprovals);
        $this->resetPage();
        if ($this->import['status'] === 'running') {
            $this->dispatch('import-started');
        } else {
            $this->historyApprovals = [];
        }
    }

    public function processNextBatch(ProcessUserImport $process): bool
    {
        $this->import = $process->handle(auth()->user(), $this->import);

        return $this->import['status'] === 'running' && $this->import['interruption'] === null;
    }

    public function selectAllDecisions(bool $selected): void
    {
        app(ProcessUserImport::class)->authorize(auth()->user(), $this->import);
        abort_unless($this->import['status'] === 'preview', 409);
        $this->historyApprovals = $selected ? $this->decisionGroups : [];
    }

    public function startOver(): void
    {
        Gate::authorize('import', User::class);
        $this->reset('import', 'historyApprovals', 'filter');
        $this->resetValidation();
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        if (! in_array($this->filter, ['all', 'ready', 'decision', 'pending', 'imported', 'ignored', 'error'], true)) {
            $this->filter = 'all';
        }
        $this->resetPage();
    }

    /** @return list<string> */
    #[Computed]
    public function decisionGroups(): array
    {
        return array_map('strval', array_keys(array_filter($this->import['groups'] ?? [], fn (array $group): bool => $group['status'] === 'decision')));
    }

    /** @return array<string, int> */
    #[Computed]
    public function counts(): array
    {
        return ProcessUserImport::counts($this->import, $this->historyApprovals);
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $rows = array_values(array_filter($this->import['rows'] ?? [], fn (array $row): bool => $this->filter === 'all'
            || ($this->filter === 'error' ? $this->import['groups'][$row['group_id']]['status'] === 'error' : $row['status'] === $this->filter)));

        return $this->paginateImportRows($rows, route('users.import'));
    }

    /** @return array<int, string> */
    #[Computed]
    public function userLinks(): array
    {
        $ids = [];
        foreach ($this->rows as $row) {
            $ids[] = $this->import['groups'][$row['group_id']]['account_id'];
        }
        $ids = array_values(array_unique(array_filter($ids)));

        return User::query()->whereIn('id', $ids)->get(['id', 'name', 'cpf', 'email'])->filter(fn (User $user): bool => Gate::allows('view', $user))
            ->mapWithKeys(fn (User $user): array => [$user->id => route('users.show', $user)])->all();
    }

    public function downloadTemplate(UserCsv $csv): StreamedResponse
    {
        Gate::authorize('import', User::class);
        $this->validate(['separator' => ['required', Rule::in([';', ','])]]);
        $selected = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'));
        $course = Course::query()->active()->where('campus_id', $selected->campus_id)->orderBy('name')->value('name');
        if ($course !== null && $csv->needsEscape($course)) {
            $course = null;
        }
        $contents = $csv->render(array_keys(UserCsv::FIELDS), [
            ['566.457.240-04', 'Ana Exemplo', 'ana@example.com', 'Orientador', '000001', ''],
            ['566.457.240-04', 'Ana Exemplo', 'ana.coordenacao@example.com', 'Coordenador de Curso', '000001', ''],
            ['353.310.950-03', 'Bruno Exemplo', 'bruno@example.com', 'Discente', '000002', $course ?? 'Nome exato de um curso ativo do campus'],
            ['677.849.900-14', 'Carla Exemplo', 'carla@example.com', 'Supervisor', '', ''],
            ['066.880.730-05', 'Daniel Exemplo', 'daniel@example.com', 'Setor de Estágios', '000004', ''],
            ['953.619.760-08', 'Elisa Exemplo', 'elisa@example.com', 'Direção de Ensino', '000005', ''],
        ], $this->separator);

        return $this->downloadCsv($contents, 'modelo-usuarios.csv');
    }

    public function downloadErrors(UserCsv $csv, ProcessUserImport $process): StreamedResponse
    {
        $process->authorize(auth()->user(), $this->import);
        abort_unless($this->counts['error'] > 0, 404);
        $contents = $csv->errorsCsv($this->import);

        return $this->downloadCsv($contents, 'erros-usuarios.csv');
    }
}; ?>

<div class="w-full space-y-8" x-data="csvImport"
    x-on:import-started="start()"
    x-on:livewire:navigating.document="pause()">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('users.index')" wire:navigate>Usuários</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Importar CSV</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Importar usuários e vínculos</flux:heading>
            <flux:text class="mt-2">As contas serão criadas ou reutilizadas pelo CPF. Os vínculos serão criados no campus selecionado.</flux:text>
        </div>
        <flux:button icon="arrow-left" variant="outline" :href="route('users.index')" wire:navigate>Voltar</flux:button>
    </div>

    @if ($import === [])
        <x-import-campus :name="app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'))->campus->name" />

        <div class="grid gap-6 lg:grid-cols-2">
            <x-import-upload-form :separator="$separator" />
            <section class="space-y-4">
                <flux:heading size="lg" level="2">Como preparar o CSV</flux:heading>
                <flux:text size="lg" class="leading-7">Baixe o modelo, abra em uma planilha e preencha uma linha para cada vínculo. Para cadastrar mais de um vínculo da mesma pessoa, repita o CPF e o nome; o e-mail pode ser diferente em cada vínculo.</flux:text>
                <flux:text size="lg" class="leading-7"><strong>Preencha sempre:</strong> CPF, nome, e-mail e tipo de vínculo. As opções de tipo são Setor de Estágios, Coordenador de Curso, Orientador, Discente, Supervisor e Direção de Ensino.</flux:text>
                <flux:text size="lg" class="leading-7"><strong>Matrícula e curso:</strong> informe a matrícula para todos os tipos, exceto Supervisor. Para Discente, preencha também o nome do curso como aparece no cadastro do campus. Deixe o curso vazio nos demais tipos.</flux:text>
                <flux:text size="lg" class="leading-7">Para uma conta nova, o e-mail da primeira linha da pessoa será usado para acessar o sistema e no primeiro vínculo. Nas demais linhas, o e-mail vale apenas para o respectivo vínculo. Se a conta já existir, seu e-mail de acesso será preservado.</flux:text>
                <flux:text size="lg" class="leading-7"><strong>Antes de salvar:</strong> mantenha as colunas do modelo e formate CPF e matrícula como texto na planilha, para preservar os zeros no início. Não acrescente senha ou outros dados ao arquivo.</flux:text>
                <flux:text size="lg" class="leading-7">Exporte a planilha como <strong>CSV</strong>, com até <strong>500 vínculos e 2 MB</strong>. Escolha na tela o separador usado na exportação: ponto e vírgula ou vírgula. Você poderá revisar os dados antes de confirmar o cadastro.</flux:text>
                <flux:text size="lg" class="leading-7">Se houver erros, baixe o arquivo de correção antes de sair desta tela. Corrija os dados na planilha e envie novamente.</flux:text>
            </section>
        </div>
    @else
        @php($counts = $this->counts)
        @php($userLinks = $this->userLinks)
        <x-import-campus :name="$import['campus_name']">
            {{ $import['status'] === 'preview' ? 'Revise todas as pessoas antes de confirmar.' : ($import['status'] === 'completed' ? 'Importação concluída.' : 'Os lotes são processados enquanto esta tela permanece aberta.') }}
        </x-import-campus>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" aria-live="polite">
            @foreach (['ready' => 'Linhas válidas', 'decision' => 'Precisam de decisão', 'pending' => 'Pendentes', 'imported' => 'Importadas', 'ignored' => 'Ignoradas', 'error' => 'Com erro'] as $status => $label)
                <div wire:key="user-import-count-{{ $status }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:text size="sm">{{ $label }}</flux:text>
                    <flux:heading size="xl">{{ $counts[$status] }}</flux:heading>
                </div>
            @endforeach
        </div>
        <flux:text>{{ $counts['people'] }} pessoas no arquivo · {{ $counts['blocked_people'] }} grupos com erro · {{ $counts['created_accounts'] }} contas criadas · {{ $counts['accounts_reused'] }} contas reutilizadas · {{ $counts['created_links'] }} vínculos criados · {{ $counts['reconciled_links'] }} vínculos encontrados na reconciliação.</flux:text>
        @if ($import['interruption'])
            <flux:callout variant="warning" icon="exclamation-triangle">{{ $import['interruption'] }}</flux:callout>
        @endif
        <flux:callout variant="warning" x-cloak x-show="message" x-text="message"></flux:callout>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="w-full sm:w-64" x-bind:inert="processing">
                <x-select wire:model.live="filter" label="Mostrar linhas" :value="$filter"
                    :options="['all' => 'Todas', 'ready' => 'Válidas', 'decision' => 'Precisam de decisão', 'pending' => 'Pendentes', 'imported' => 'Importadas', 'ignored' => 'Ignoradas', 'error' => 'Grupos com erro']" />
            </div>
            <x-import-actions :status="$import['status']" :errors="$counts['error']" :confirm-disabled="$counts['eligible_people'] === 0" />
        </div>
        @if ($import['status'] === 'preview')
            <flux:callout>
                Para {{ $counts['eligible_people'] }} pessoas: criar {{ $counts['new_accounts'] }} contas, reutilizar {{ $counts['reused_accounts'] }} contas e criar {{ $counts['new_links'] }} vínculos.
                {{ $counts['existing_links'] }} linhas de vínculos coincidentes ou repetidos serão ignoradas. Pessoas com erro não serão processadas.
            </flux:callout>
            @if ($this->decisionGroups !== [])
                <div class="space-y-2">
                    <div class="flex flex-wrap gap-3">
                        <flux:button variant="outline" wire:click="selectAllDecisions(true)" wire:loading.attr="disabled">Autorizar todas as criações com histórico desativado</flux:button>
                        <flux:button variant="ghost" wire:click="selectAllDecisions(false)" wire:loading.attr="disabled">Desmarcar todas</flux:button>
                    </div>
                    <flux:text size="sm">A seleção abrange todas as pessoas elegíveis em todas as páginas, independentemente do filtro. Autoriza criar novos vínculos ativos, preservando os desativados. Não autoriza reativação, atualização ou conflito de matrícula.</flux:text>
                </div>
            @endif
        @endif
        <flux:error name="historyApprovals" />
        <flux:text x-cloak x-show="processing" role="status">Processando até 25 pessoas por lote, sem dividir os vínculos da mesma pessoa…</flux:text>
        <div x-bind:inert="processing" x-bind:aria-busy="processing">
            <x-loading-overlay target="filter,gotoPage,nextPage,previousPage,processNextBatch" label="Atualizando linhas…">
                <flux:table :paginate="$this->rows">
                    <flux:table.columns>
                        <flux:table.column>Linha original</flux:table.column>
                        <flux:table.column>Pessoa e vínculo</flux:table.column>
                        <flux:table.column>Situação e revisão</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->rows as $row)
                            @php($group = $import['groups'][$row['group_id']])
                            <flux:table.row wire:key="user-import-row-{{ $row['position'] }}">
                                <flux:table.cell>
                                    {{ $row['original_line'] }}
                                    @if ($row['line'] !== $row['original_line'])
                                        <flux:text size="xs">Neste arquivo: {{ $row['line'] }}</flux:text>
                                    @endif
                                    <flux:text size="xs">{{ count($group['positions']) }} linhas neste grupo</flux:text>
                                </flux:table.cell>
                                <flux:table.cell class="min-w-64 whitespace-normal">
                                    <flux:text class="font-medium">{{ Illuminate\Support\Str::limit($group['account']['name'] ?? $row['payload']['name'], 120) }}</flux:text>
                                    <flux:text size="sm">{{ $group['cpf'] }}</flux:text>
                                    <flux:text size="sm">Acesso: {{ $group['login_email'] }}</flux:text>
                                    <flux:text size="sm">E-mail do vínculo: {{ $row['payload']['email'] }}</flux:text>
                                    <flux:text size="sm">{{ App\Enums\AffiliationType::tryFrom($row['payload']['type'])?->label() ?? 'Tipo inválido' }} · {{ $row['payload']['registration_number'] ?? 'Sem matrícula' }}</flux:text>
                                    <x-import-row-details :row="$row" />
                                    @if ($group['account_id'] !== null && isset($userLinks[$group['account_id']]))
                                        <flux:link :href="$userLinks[$group['account_id']]" wire:navigate>Ver usuário</flux:link>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="min-w-64 whitespace-normal">
                                    <x-import-row-feedback :row="$row">
                                        @foreach ($group['warnings'] as $warning)
                                            <flux:text wire:key="user-import-warning-{{ $row['position'] }}-{{ $loop->index }}" size="sm" class="mt-2">{{ $warning }}</flux:text>
                                        @endforeach
                                    </x-import-row-feedback>
                                    @if ($import['status'] === 'preview' && $group['status'] === 'decision')
                                        <flux:checkbox wire:model.live="historyApprovals" :value="(string) $row['group_id']" label="Criar novos vínculos desta pessoa, preservando os desativados" class="mt-3" />
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row wire:key="user-import-empty">
                                <flux:table.cell colspan="3">Nenhuma linha neste filtro.</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </x-loading-overlay>
        </div>
        <flux:text size="sm">O CSV de erros inclui todas as linhas das pessoas não processadas. Corrija os dados e reenvie preservando as colunas __sge_. Redistribua eventuais valores de __sge_excedentes e limpe essa célula após revisar.</flux:text>
        @if ($import['status'] === 'completed')
            @can('viewAny', \App\Models\EmailDeliveryAttempt::class)
                <flux:button variant="outline" icon="envelope" :href="route('email-logs.index')" wire:navigate>Consultar envios de e-mail</flux:button>
            @endcan
        @endif
        <flux:text size="sm">Contas novas recebem um único e-mail com as instruções de acesso e os detalhes do primeiro vínculo. Vínculos adicionais recebem um aviso próprio. Os e-mails são enviados após a confirmação dos cadastros. A conclusão da importação não confirma a entrega dos e-mails. Reenvios não geram avisos para vínculos coincidentes já existentes.</flux:text>
        <flux:text size="sm">A prévia não é armazenada. Se sair durante um lote, alguns cadastros podem ser confirmados. Reenvie o arquivo para conferir o que já existe e processar o restante.</flux:text>
    @endif
</div>
