<?php

use App\Actions\PrepareGrantingPartyImport;
use App\Actions\ProcessGrantingPartyImport;
use App\Concerns\InteractsWithCsvImport;
use App\Models\GrantingParty;
use App\Support\ActiveAffiliationContext;
use App\Support\GrantingPartyCsv;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Importar partes concedentes')] class extends Component
{
    use InteractsWithCsvImport;

    /** @var list<int|string> */
    public array $duplicateApprovals = [];

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
        $this->analyzeCsv(fn (string $contents, string $separator): array => $prepare->handle(auth()->user(), $contents, $separator));
        $this->duplicateApprovals = [];
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
        $this->dispatch('import-started');
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

        return $this->paginateImportRows($rows, route('granting-parties.import'));
    }

    public function downloadTemplate(GrantingPartyCsv $csv): StreamedResponse
    {
        Gate::authorize('viewAny', GrantingParty::class);
        $this->validate(['separator' => ['required', Rule::in([';', ','])]]);
        $values = ['tipo_documento' => 'cnpj', 'documento' => 'ZG.MXB.TA3/0001-93', 'nome' => 'Empresa Exemplo',
            'representante_nome' => 'Maria Exemplo', 'representante_cargo' => 'Diretora', 'atividade' => 'Serviços de informática',
            'logradouro' => 'Rua Exemplo', 'numero' => '100', 'bairro' => 'Centro', 'cep' => '01001000', 'cidade' => 'São Paulo', 'uf' => 'SP'];
        $headers = array_keys(GrantingPartyCsv::FIELDS);
        $numericValues = [...$values, 'documento' => '83.916.474/0001-05', 'nome' => 'Empresa Exemplo Numérica'];
        $contents = $csv->render($headers, [
            array_map(fn (string $header): string => $values[$header] ?? '', $headers),
            array_map(fn (string $header): string => $numericValues[$header] ?? '', $headers),
        ], $this->separator);

        return $this->downloadCsv($contents, 'modelo-partes-concedentes.csv');
    }

    public function downloadErrors(GrantingPartyCsv $csv, ProcessGrantingPartyImport $process): StreamedResponse
    {
        $process->authorize(auth()->user(), $this->import);
        abort_unless($this->counts()['error'] > 0, 404);
        $contents = $csv->errorsCsv($this->import);

        return $this->downloadCsv($contents, 'erros-partes-concedentes.csv');
    }
}; ?>

<div class="w-full space-y-8" x-data="csvImport"
    x-on:import-started="start()"
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
        <flux:button icon="arrow-left" variant="outline" :href="route('granting-parties.index')" wire:navigate>Voltar</flux:button>
    </div>

    @if ($import === [])
        <x-import-campus :name="app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'))->campus->name" />

        <div class="grid gap-6 lg:grid-cols-2">
            <x-import-upload-form :separator="$separator" />
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
        <x-import-campus :name="$import['campus_name']">
            @if ($import['status'] === 'preview')
                Revise as linhas e confirme as duplicidades que deseja cadastrar.
            @elseif ($import['status'] === 'completed')
                Importação concluída.
            @else
                Os lotes são processados enquanto esta tela permanece aberta.
            @endif
        </x-import-campus>

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
            <x-import-actions :status="$import['status']" :errors="$this->counts['error']">
                Confirmar importação de {{ $this->selectedCount }} {{ $this->selectedCount === 1 ? 'linha' : 'linhas' }}
            </x-import-actions>
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
                                    <x-import-row-details :row="$row" />
                                    @if ($row['status'] === 'imported')
                                        <flux:link :href="route('granting-parties.show', $row['granting_party_id'])" wire:navigate>Ver cadastro #{{ $row['granting_party_id'] }}</flux:link>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="min-w-64 whitespace-normal">
                                    <x-import-row-feedback :row="$row" />
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
