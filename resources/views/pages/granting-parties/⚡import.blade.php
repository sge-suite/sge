<?php

use App\Actions\PrepareGrantingPartyImport;
use App\Actions\ProcessGrantingPartyImport;
use App\Models\GrantingParty;
use App\Support\ActiveAffiliationContext;
use App\Support\GrantingPartyCsv;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Importar partes concedentes')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    public ?TemporaryUploadedFile $csv = null;

    public string $separator = ';';

    /** @var array<string, mixed> */
    #[Locked]
    public array $import = [];

    /** @var list<int|string> */
    public array $duplicateApprovals = [];

    public string $filter = 'all';

    public function boot(): void
    {
        Gate::authorize('viewAny', GrantingParty::class);
        if ($this->import !== []) {
            app(ProcessGrantingPartyImport::class)->authorize(auth()->user(), $this->import);
        }
    }

    public function mount(): void
    {
        Gate::authorize('create', GrantingParty::class);
    }

    public function analyze(PrepareGrantingPartyImport $prepare): void
    {
        Gate::authorize('create', GrantingParty::class);
        abort_if(($this->import['status'] ?? null) === 'running', 409);
        $file = $this->csv;
        try {
            $this->validate([
                'csv' => ['required', 'file', 'extensions:csv', 'max:8192', 'mimetypes:text/plain,text/csv,text/x-csv,application/csv,application/vnd.ms-excel,application/octet-stream'],
                'separator' => ['required', Rule::in([';', ','])],
            ], attributes: ['csv' => 'arquivo CSV', 'separator' => 'separador']);
            $contents = $file?->get();
            abort_unless(is_string($contents), 422);
            $this->import = $prepare->handle(auth()->user(), $contents, $this->separator);
            $this->duplicateApprovals = [];
            $this->filter = 'all';
            $this->resetPage();
        } finally {
            $file?->delete();
            $this->csv = null;
        }
    }

    public function confirm(ProcessGrantingPartyImport $process): void
    {
        $process->authorize(auth()->user(), $this->import, true);
        abort_unless($this->import['status'] === 'preview', 409);
        $this->validate(['duplicateApprovals' => ['array', 'max:500'], 'duplicateApprovals.*' => ['integer', 'min:0', 'max:1999']]);
        $selected = array_map('strval', $this->duplicateApprovals);
        foreach ($this->import['rows'] as &$row) {
            if ($row['status'] === 'ready') {
                $row['duplicate_approved'] = $row['duplicate_required'] && in_array((string) $row['position'], $selected, true);
                $row['status'] = ! $row['duplicate_required'] || $row['duplicate_approved'] ? 'pending' : 'ignored';
                if ($row['status'] === 'ignored') {
                    $row['reason'] = 'Duplicidade não autorizada.';
                }
            }
        }
        unset($row);
        $this->import['status'] = 'running';
        $this->resetPage();
        $this->dispatch('granting-party-import-started');
    }

    public function processNextBatch(ProcessGrantingPartyImport $process): bool
    {
        $this->import = $process->handle(auth()->user(), $this->import);

        return $this->import['status'] === 'running' && $this->import['interruption'] === null;
    }

    /** @return list<string> */
    #[Computed]
    public function duplicatePositions(): array
    {
        return array_values(array_map(
            fn (array $row): string => (string) $row['position'],
            array_filter($this->import['rows'] ?? [], fn (array $row): bool => $row['status'] === 'ready' && $row['duplicate_required']),
        ));
    }

    public function selectAllDuplicates(bool $selected): void
    {
        app(ProcessGrantingPartyImport::class)->authorize(auth()->user(), $this->import, true);
        abort_unless($this->import['status'] === 'preview', 409);
        $this->duplicateApprovals = $selected ? $this->duplicatePositions : [];
    }

    public function startOver(): void
    {
        Gate::authorize('create', GrantingParty::class);
        $this->reset('import', 'duplicateApprovals', 'filter');
        $this->resetValidation();
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        if (! in_array($this->filter, ['all', 'ready', 'duplicate', 'pending', 'imported', 'ignored', 'error'], true)) {
            $this->filter = 'all';
        }
        $this->resetPage();
    }

    /** @return array<string, int> */
    #[Computed]
    public function counts(): array
    {
        return ProcessGrantingPartyImport::counts($this->import);
    }

    #[Computed]
    public function selectedCount(): int
    {
        $selected = array_map('strval', $this->duplicateApprovals);

        return count(array_filter($this->import['rows'] ?? [], fn (array $row): bool => $row['status'] === 'ready'
            && (! $row['duplicate_required'] || in_array((string) $row['position'], $selected, true))));
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $rows = array_values(array_filter($this->import['rows'] ?? [], fn (array $row): bool => $this->filter === 'all'
            || ($this->filter === 'duplicate' ? $row['duplicate_required'] : $row['status'] === $this->filter)));
        $page = max(1, (int) $this->getPage());

        return new LengthAwarePaginator(array_slice($rows, ($page - 1) * 100, 100), count($rows), 100, $page, ['path' => route('granting-parties.import')]);
    }

    public function downloadTemplate(GrantingPartyCsv $csv): StreamedResponse
    {
        Gate::authorize('viewAny', GrantingParty::class);
        $values = ['tipo_documento' => 'cnpj', 'documento' => 'ZG.MXB.TA3/0001-93', 'nome' => 'Empresa Exemplo',
            'representante_nome' => 'Maria Exemplo', 'representante_cargo' => 'Diretora', 'atividade' => 'Serviços de informática',
            'logradouro' => 'Rua Exemplo', 'numero' => '100', 'bairro' => 'Centro', 'cep' => '01001000', 'cidade' => 'São Paulo', 'uf' => 'SP'];
        $headers = array_keys(GrantingPartyCsv::FIELDS);
        $numericValues = [...$values, 'documento' => '83.916.474/0001-05', 'nome' => 'Empresa Exemplo Numérica'];
        $contents = $csv->render($headers, [
            array_map(fn (string $header): string => $values[$header] ?? '', $headers),
            array_map(fn (string $header): string => $numericValues[$header] ?? '', $headers),
        ]);

        return response()->streamDownload(fn () => print($contents), 'modelo-partes-concedentes.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function downloadErrors(GrantingPartyCsv $csv, ProcessGrantingPartyImport $process): StreamedResponse
    {
        $process->authorize(auth()->user(), $this->import);
        abort_unless($this->counts()['error'] > 0, 404);
        $contents = $csv->errorsCsv($this->import);

        return response()->streamDownload(fn () => print($contents), 'erros-partes-concedentes.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}; ?>

<div class="w-full space-y-8" x-data="grantingPartyImport"
    x-on:granting-party-import-started="start()"
    x-on:livewire:navigating.document="pause()">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('granting-parties.index')" wire:navigate>Partes concedentes</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Importar CSV</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Importar partes concedentes</flux:heading>
            <flux:text class="mt-2">Os cadastros serão criados no campus do vínculo selecionado.</flux:text>
        </div>
        <flux:button variant="outline" :href="route('granting-parties.index')" wire:navigate>Voltar às partes concedentes</flux:button>
    </div>

    @if ($import === [])
        <flux:callout>
            Campus de destino: <strong>{{ app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'))->campus->name }}</strong>.
            O arquivo não pode indicar outro campus.
        </flux:callout>

        <div class="grid gap-6 lg:grid-cols-2">
            <form wire:submit="analyze" class="space-y-5">
                <flux:heading level="2">Enviar arquivo</flux:heading>
                <flux:input type="file" wire:model="csv" accept=".csv,text/csv,text/plain" label="Arquivo CSV" />
                <x-select wire:model="separator" label="Separador" :value="$separator" :options="[';' => 'Ponto e vírgula (;)', ',' => 'Vírgula (,)']" />
                <flux:error name="separator" />
                <div class="flex flex-wrap gap-3">
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="csv,analyze">Validar e mostrar prévia</flux:button>
                    <flux:button type="button" variant="outline" wire:click="downloadTemplate">Baixar modelo CSV</flux:button>
                </div>
                <flux:text size="sm" wire:loading wire:target="csv,analyze" role="status">Lendo e validando arquivo…</flux:text>
            </form>
            <section class="space-y-3">
                <flux:heading level="2">Como preparar o CSV</flux:heading>
                <flux:text>Até 500 registros e 2 MiB (até 8 MiB para o CSV de erros). Salve em UTF-8, com ou sem BOM. São aceitas quebras de linha LF e CRLF e aspas duplas para campos com separadores ou quebras de linha.</flux:text>
                <flux:text>Obrigatórios: tipo_documento (cpf/cnpj), documento, nome, representante_nome, representante_cargo, atividade, logradouro, numero, bairro, cep, cidade e uf.</flux:text>
                <flux:text>Opcionais: telefone, email, conselho_profissional, registro_conselho e processo_credenciamento.</flux:text>
                <flux:text>Na planilha, importe CPF/CNPJ, telefone e CEP como texto para preservar zeros à esquerda. Informe cidade por nome e UF; não use código IBGE.</flux:text>
                <flux:text>Cadastros existentes serão preservados. Documentos repetidos precisarão de confirmação individual para criar outro cadastro.</flux:text>
                <flux:text size="sm">A prévia e o resultado ficam disponíveis apenas nesta tela. Baixe as linhas com erro antes de sair. Ao retornar, reenvie o arquivo.</flux:text>
            </section>
        </div>
    @else
        <flux:callout>
            Campus: <strong>{{ $import['campus_name'] }}</strong>.
            @if ($import['status'] === 'preview')
                Revise as linhas e confirme as duplicidades que deseja cadastrar.
            @elseif ($import['status'] === 'completed')
                Importação concluída.
            @else
                Os lotes são processados enquanto esta tela permanece aberta.
            @endif
        </flux:callout>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-5" aria-live="polite">
            @foreach (['ready' => 'Válidas', 'pending' => 'Pendentes', 'imported' => 'Importadas', 'ignored' => 'Ignoradas', 'error' => 'Com erro'] as $status => $label)
                <div wire:key="import-count-{{ $status }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:text size="sm">{{ $label }}</flux:text>
                    <flux:heading size="xl">{{ $this->counts[$status] }}</flux:heading>
                </div>
            @endforeach
        </div>

        @if ($import['interruption'])
            <flux:callout variant="warning" icon="exclamation-triangle">{{ $import['interruption'] }}</flux:callout>
        @endif
        <flux:callout variant="warning" x-cloak x-show="message" x-text="message"></flux:callout>

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="w-full sm:w-64" x-bind:inert="processing">
                <x-select wire:model.live="filter" label="Mostrar linhas" :value="$filter"
                    :options="['all' => 'Todas', 'ready' => 'Válidas', 'duplicate' => 'Com documento repetido', 'pending' => 'Pendentes', 'imported' => 'Importadas', 'ignored' => 'Ignoradas', 'error' => 'Com erro']" />
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if ($this->counts['error'] > 0)
                    <flux:button variant="outline" wire:click="downloadErrors" x-bind:disabled="processing">Baixar CSV de erros</flux:button>
                @endif
                @if ($import['status'] === 'preview')
                    <flux:button variant="primary" wire:click="confirm" wire:loading.attr="disabled">
                        Confirmar importação de {{ $this->selectedCount }} {{ $this->selectedCount === 1 ? 'linha' : 'linhas' }}
                    </flux:button>
                @elseif ($import['status'] === 'running')
                    <flux:button variant="primary" x-on:click="start()" x-bind:disabled="processing">Continuar processamento</flux:button>
                    <flux:button variant="outline" x-on:click="pause()" x-show="processing">Pausar após este lote</flux:button>
                @endif
                <flux:button variant="outline" wire:click="startOver" x-bind:disabled="processing">Enviar outro arquivo</flux:button>
            </div>
        </div>

        <flux:text x-cloak x-show="processing" role="status">Processando até 25 linhas por lote…</flux:text>
        @if ($import['status'] === 'preview' && $this->duplicatePositions !== [])
            <div class="space-y-2">
                <div class="flex flex-wrap gap-3">
                    <flux:button variant="outline" wire:click="selectAllDuplicates(true)" wire:loading.attr="disabled">Selecionar todas as duplicidades</flux:button>
                    <flux:button variant="ghost" wire:click="selectAllDuplicates(false)" wire:loading.attr="disabled">Desmarcar todas</flux:button>
                </div>
                <flux:text size="sm">A seleção abrange todas as páginas e filtros da prévia e autoriza criar outro cadastro para cada linha válida com documento repetido.</flux:text>
            </div>
        @endif
        <flux:error name="duplicateApprovals" />

        <div x-bind:inert="processing" x-bind:aria-busy="processing">
            <x-loading-overlay target="filter,gotoPage,nextPage,previousPage,processNextBatch" label="Atualizando linhas…">
                <flux:table :paginate="$this->rows">
                    <flux:table.columns>
                        <flux:table.column>Linha original</flux:table.column>
                        <flux:table.column>Parte concedente</flux:table.column>
                        <flux:table.column>Situação e revisão</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->rows as $row)
                            <flux:table.row wire:key="import-row-{{ $row['position'] }}">
                                <flux:table.cell>
                                    {{ $row['original_line'] }}
                                    @if ($row['line'] !== $row['original_line'])
                                        <flux:text size="xs">Neste arquivo: {{ $row['line'] }}</flux:text>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:text class="font-medium">{{ Illuminate\Support\Str::limit($row['payload']['name'] ?? '—', 120) }}</flux:text>
                                    <flux:text size="sm">{{ Illuminate\Support\Str::limit($row['payload']['document_number'] ?? '', 40) }}</flux:text>
                                    @if (isset($row['payload']['address']))
                                        <flux:text size="sm">{{ Illuminate\Support\Str::limit($row['payload']['address']['street'].', '.$row['payload']['address']['number'], 120) }}</flux:text>
                                    @endif
                                    @if ($row['original'] !== [])
                                        <details class="mt-2 whitespace-normal">
                                            <summary class="cursor-pointer text-sm text-zinc-600 dark:text-zinc-300">Ver dados da linha</summary>
                                            <dl class="mt-2 grid gap-2 text-sm">
                                                @foreach ($row['original'] as $header => $value)
                                                    <div wire:key="import-field-{{ $row['position'] }}-{{ $loop->index }}" class="max-w-lg break-words">
                                                        <dt class="font-medium">{{ $header }}</dt>
                                                        <dd>{{ $value === '' ? '—' : Illuminate\Support\Str::limit($value, 300) }}</dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        </details>
                                    @endif
                                    @if ($row['status'] === 'imported')
                                        <flux:link :href="route('granting-parties.show', $row['granting_party_id'])" wire:navigate>Ver cadastro #{{ $row['granting_party_id'] }}</flux:link>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="min-w-64 whitespace-normal">
                                    <flux:badge :color="match ($row['status']) { 'error' => 'red', 'imported' => 'green', 'ignored' => 'zinc', default => 'blue' }">
                                        {{ match ($row['status']) { 'ready' => 'Válida', 'pending' => 'Pendente', 'imported' => 'Importada', 'ignored' => 'Ignorada', default => 'Com erro' } }}
                                    </flux:badge>
                                    @if ($row['reason'])
                                        <flux:text size="sm" class="mt-2">{{ $row['reason'] }}</flux:text>
                                    @endif
                                    @foreach ($row['errors'] as $field => $messages)
                                        @foreach ($messages as $messageIndex => $message)
                                            <flux:text wire:key="import-error-{{ $row['position'] }}-{{ $field }}-{{ $messageIndex }}" size="sm" class="mt-2 text-red-600 dark:text-red-400">{{ $field }}: {{ $message }}</flux:text>
                                        @endforeach
                                    @endforeach
                                    @if ($row['duplicate_required'])
                                        <flux:text size="sm" class="mt-2">CPF/CNPJ repetido no arquivo ou no campus.</flux:text>
                                        @foreach ($import['duplicates'][$row['document_key']] ?? [] as $candidate)
                                            <flux:text wire:key="import-candidate-{{ $row['position'] }}-{{ $candidate['id'] }}" size="sm" class="mt-1">
                                                #{{ $candidate['id'] }} · {{ $candidate['name'] }} · {{ $candidate['address'] }} · {{ $candidate['inactive'] ? 'Desativada' : 'Ativa' }}
                                            </flux:text>
                                        @endforeach
                                        @if ($import['status'] === 'preview' && $row['status'] === 'ready')
                                            <flux:checkbox wire:model.live="duplicateApprovals" :value="(string) $row['position']" label="Confirmo criar outro cadastro com este CPF/CNPJ" class="mt-3" />
                                        @elseif ($row['duplicate_approved'])
                                            <flux:text size="sm" class="mt-2">Criação de outro cadastro autorizada.</flux:text>
                                        @endif
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row wire:key="import-empty">
                                <flux:table.cell colspan="3">Nenhuma linha neste filtro.</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </x-loading-overlay>
        </div>

        <flux:text size="sm">Corrija as colunas cadastrais do CSV de erros e reenvie preservando as colunas __sge_. A numeração original será mantida. O download é gerado na hora e não fica armazenado no servidor.</flux:text>
        <flux:text size="sm">Se houver valores em __sge_excedentes, redistribua-os nas colunas corretas e limpe essa célula após revisar.</flux:text>
        <flux:text size="sm">Ao reenviar o arquivo original, as concedentes já cadastradas aparecerão como duplicadas e precisarão de nova confirmação para criar outro cadastro.</flux:text>
    @endif
</div>
