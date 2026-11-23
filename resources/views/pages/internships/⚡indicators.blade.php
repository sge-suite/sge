<?php

use App\Actions\UnifyCancellationReasons;
use App\Enums\InternshipStatus;
use App\Models\Affiliation;
use App\Models\Internship;
use App\Models\InternshipType;
use App\Support\InternshipIndicators;
use App\Support\PageSize;
use App\Support\StudentInternshipContext;
use Flux\Flux;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\MessageBag;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Indicadores')] class extends Component
{
    use WithPagination;

    #[Locked] public int $affiliationId;
    #[Url(except: 'courses')] public string $metric = 'courses';
    #[Url(as: 'course', except: '')] public string $courseId = '';
    #[Url(as: 'type', except: '')] public string $typeId = '';
    #[Url(except: '')] public string $status = '';
    #[Url(except: '')] public string $from = '';
    #[Url(except: '')] public string $to = '';
    #[Url(except: '')] public string $group = '';

    public bool $showExportModal = false;
    public bool $showRecordsModal = false;
    public bool $showUnifyModal = false;
    public bool $showMethodologyModal = false;
    public string $exportScope = 'details';

    /** @var list<string> */
    public array $selectedGroups = [];
    public string $unifiedReason = '';

    protected InternshipIndicators $indicators;
    protected Affiliation $affiliation;

    public function boot(InternshipIndicators $indicators, StudentInternshipContext $context): void
    {
        Gate::authorize('viewIndicators', Internship::class);
        $this->indicators = $indicators;
        $this->affiliation = $context->selected(auth()->user());
    }

    public function mount(): void
    {
        $this->affiliationId = $this->affiliation->id;
        if ($this->group !== '') {
            $this->showRecordsModal = true;
        }
    }

    public function hydrate(): void
    {
        abort_unless($this->affiliationId === $this->affiliation->id, 403);
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['metric', 'courseId', 'typeId', 'status', 'from', 'to'], true)) {
            return;
        }

        $this->reset('group', 'selectedGroups', 'unifiedReason', 'showRecordsModal', 'showUnifyModal');
        if ($property === 'metric') {
            $this->reset('status');
        }
        if ($property === 'courseId') {
            $this->reset('typeId');
        }
        $this->resetPage();
    }

    public function openGroupRecords(string $group = ''): void
    {
        $this->group = $group;
        $this->resetPage();
        $this->showRecordsModal = true;
    }

    public function closeRecordsModal(): void
    {
        $this->showRecordsModal = false;
        $this->group = '';
        $this->resetPage();
    }

    public function openUnifyModal(): void
    {
        Gate::authorize('unifyCancellationReasons', Internship::class);
        if ($this->selectedGroups === []) {
            return;
        }

        $firstSelected = $this->summary->first(fn (stdClass $item): bool => in_array($item->group_key, $this->selectedGroups, true));
        $this->unifiedReason = $firstSelected !== null ? (string) $firstSelected->group_name : '';
        $this->resetErrorBag();
        $this->showUnifyModal = true;
    }

    public function unifySelectedReasons(UnifyCancellationReasons $unify): void
    {
        Gate::authorize('unifyCancellationReasons', Internship::class);
        $updated = $unify->handle(
            auth()->user(),
            $this->affiliationId,
            $this->selectedGroups,
            $this->unifiedReason,
            $this->filters(),
        );

        $this->reset('selectedGroups', 'unifiedReason', 'showUnifyModal', 'group');
        unset($this->dataset, $this->summary, $this->records);

        Flux::toast(
            $updated === 1 ? '1 cancelamento atualizado com o motivo unificado.' : "{$updated} cancelamentos atualizados com o motivo unificado.",
            variant: 'success',
        );
    }

    public function clearFilters(): void
    {
        $this->reset('courseId', 'typeId', 'status', 'from', 'to', 'group', 'selectedGroups');
        $this->resetPage();
    }

    /** @return array<string, string> */
    private function filters(): array
    {
        return array_filter([
            'metric' => $this->metric,
            'course' => $this->courseId,
            'type' => $this->typeId,
            'status' => $this->status,
            'from' => $this->from,
            'to' => $this->to,
            'group' => $this->group,
        ], fn (string $value): bool => $value !== '');
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return $this->courseId !== '' || $this->typeId !== '' || $this->status !== '' || $this->from !== '' || $this->to !== '';
    }

    #[Computed]
    public function filterErrors(): MessageBag
    {
        return $this->indicators->validator($this->affiliation, $this->filters())->errors();
    }

    /** @return array<string, array<string, string>> */
    #[Computed]
    public function sections(): array
    {
        return $this->indicators->sections($this->affiliation);
    }

    /** @return array<string, string> */
    #[Computed]
    public function metricOptions(): array
    {
        return $this->indicators->allowedMetrics($this->affiliation);
    }

    #[Computed]
    public function activeMetricLabel(): string
    {
        return $this->metricOptions[$this->metric] ?? 'Indicador';
    }

    /** @return array<int|string, string> */
    #[Computed]
    public function courseOptions(): array
    {
        return ['' => 'Todos os cursos'] + $this->indicators->courses($this->affiliation)->orderBy('name')->orderBy('id')->pluck('name', 'id')->all();
    }

    /** @return array<int|string, string> */
    #[Computed]
    public function typeOptions(): array
    {
        $courses = $this->indicators->courses($this->affiliation);
        if ($this->courseId !== '' && isset($this->courseOptions[$this->courseId])) {
            $courses->whereKey((int) $this->courseId);
        }

        return ['' => 'Todos os tipos'] + InternshipType::query()->whereIn('course_id', $courses->select('id'))
            ->with('course:id,name')->orderBy('name')->orderBy('id')->get(['id', 'course_id', 'name'])
            ->mapWithKeys(fn (InternshipType $type): array => [$type->id => $type->name.' · '.$type->course->name])->all();
    }

    #[Computed]
    public function periodLabel(): string
    {
        return $this->indicators->periodLabel($this->metric);
    }

    #[Computed]
    public function definition(): string
    {
        return $this->indicators->definition($this->metric);
    }

    /** @return array{summary: string, data_used: list<string>, how_calculated: list<string>, period_filter: string, notes: list<string>} */
    #[Computed]
    public function methodology(): array
    {
        return $this->indicators->methodology($this->metric);
    }

    #[Computed]
    public function chartKind(): string
    {
        return $this->indicators->chartKind($this->metric);
    }

    /** @return array{total: int, in_progress: int, completed: int, addended: int, cancelled: int} */
    #[Computed]
    public function overview(): array
    {
        return $this->indicators->overview($this->affiliation, $this->filters());
    }

    #[Computed]
    public function dataset(): Builder
    {
        return $this->indicators->dataset($this->affiliation, $this->filters());
    }

    /** @return Collection<int, stdClass> */
    #[Computed]
    public function summary(): Collection
    {
        return $this->indicators->summary($this->dataset, $this->metric);
    }

    #[Computed]
    public function deadlineSummary(): ?stdClass
    {
        return $this->indicators->deadlineCounts(clone $this->dataset)->first();
    }

    /** @return array{avg: ?float, min: ?float, max: ?float, measured: int, missing: int, total: int} */
    #[Computed]
    public function gradeStats(): array
    {
        return $this->indicators->gradeStats($this->dataset);
    }

    /** @return array{avg: ?float, median: ?float, min: ?float, max: ?float, measured: int, missing: int, total: int} */
    #[Computed]
    public function processingStats(): array
    {
        return $this->indicators->processingStats($this->dataset);
    }

    /** @return LengthAwarePaginator<int, stdClass> */
    #[Computed]
    public function records(): LengthAwarePaginator
    {
        return $this->indicators->details($this->dataset, $this->metric, $this->group)->paginate(PageSize::PER_PAGE);
    }

    #[Computed]
    public function selectedGroupLabel(): ?string
    {
        if ($this->group === '') {
            return null;
        }
        $found = $this->summary->firstWhere('group_key', $this->group);

        return $found !== null ? $this->groupLabel((string) $found->group_name) : $this->group;
    }

    /** @return list<string> */
    #[Computed]
    public function headings(): array
    {
        return $this->indicators->headings($this->metric);
    }

    public function groupLabel(string $name): string
    {
        return $this->indicators->groupLabel($name, $this->metric);
    }

    /** @return list<string|int|null> */
    protected function cells(stdClass $row): array
    {
        return $this->indicators->cells($row, $this->metric);
    }

    #[Computed]
    public function canUnifyCancellations(): bool
    {
        return $this->metric === 'cancellations' && Gate::allows('unifyCancellationReasons', Internship::class);
    }

    #[Computed]
    public function csvUrl(): string
    {
        return route('internship-indicators.export', [
            'affiliation' => $this->affiliationId,
            'scope' => $this->exportScope,
        ] + $this->filters());
    }

    #[Computed]
    public function modalCsvUrl(): string
    {
        return route('internship-indicators.export', [
            'affiliation' => $this->affiliationId,
            'scope' => 'details',
        ] + $this->filters());
    }
}; ?>

<div class="w-full space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading level="1" size="xl">Indicadores</flux:heading>
            <flux:text class="mt-2">Acompanhe os indicadores de estágio, visualize comparativos e exporte dados ou consulte registros sob demanda.</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($this->filterErrors->isEmpty())
                <flux:button variant="outline" icon="magnifying-glass" wire:click="openGroupRecords('')">Consultar registros</flux:button>
                <flux:button variant="primary" icon="arrow-down-tray" x-on:click="$wire.showExportModal = true">Exportar dados</flux:button>
            @endif
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 xl:items-end">
        <x-select wire:model.live="metric" label="Indicador" :value="$metric" :options="$this->metricOptions" :groups="$this->sections" searchable />
        <x-select wire:model.live="courseId" label="Curso" :value="$courseId" :options="$this->courseOptions" searchable />
        <x-select wire:model.live="typeId" label="Tipo de estágio" :value="$typeId" :options="$this->typeOptions" searchable />
        <flux:input wire:model.live="from" type="date" :label="'De · '.$this->periodLabel" />
        <flux:input wire:model.live="to" type="date" :label="'Até · '.$this->periodLabel" />
        @unless (in_array($metric, ['grade_distribution', 'grade_averages', 'cancellations', 'completion_deadline'], true))
            <x-select wire:model.live="status" label="Situação atual" :value="$status" :options="['' => 'Todas as situações'] + InternshipStatus::options()" />
        @endunless
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:text size="sm">
            @if ($this->periodLabel === 'Início previsto')
                O período filtra pela data de <strong>Início previsto</strong> (nos cartões de resumo e no indicador).
            @else
                Neste indicador, o período filtra por <strong>{{ $this->periodLabel }}</strong>. Os cartões de resumo geral abaixo continuam considerando o <strong>Início previsto</strong>.
            @endif
        </flux:text>
        @if ($this->hasFilters)
            <flux:button variant="outline" size="sm" wire:click="clearFilters">Limpar filtros</flux:button>
        @endif
    </div>

    <x-loading-overlay target="metric,courseId,typeId,status,from,to,clearFilters,openGroupRecords,unifySelectedReasons" label="Atualizando indicadores…" :dim-while-loading="true">
        @if ($this->filterErrors->isNotEmpty())
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.text>{{ $this->filterErrors->first() }}</flux:callout.text>
            </flux:callout>
        @else
            @php
                $overview = $this->overview;
                $summary = $this->summary;
            @endphp
            <div class="space-y-8">
                <section aria-label="Resumo geral do recorte" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <x-dashboard-metric label="Total no recorte" :value="$overview['total']" icon="briefcase" description="Por início previsto" />
                    <x-dashboard-metric label="Em execução ou liberados" :value="$overview['in_progress']" icon="play" />
                    <x-dashboard-metric label="Concluídos" :value="$overview['completed']" icon="check-circle" />
                    <x-dashboard-metric label="Estágios aditivados" :value="$overview['addended']" icon="document-duplicate" description="Com aditivo assinado" />
                    <x-dashboard-metric label="Cancelados" :value="$overview['cancelled']" icon="x-circle" />
                </section>

                <section class="space-y-4" aria-labelledby="indicator-summary">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <flux:heading id="indicator-summary" level="2" size="lg">{{ $this->activeMetricLabel }}</flux:heading>
                            <flux:text class="mt-1">{{ $this->definition }}</flux:text>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="question-mark-circle"
                                x-on:click="$wire.showMethodologyModal = true"
                            >
                                Como é calculado
                            </flux:button>
                            @if ($this->canUnifyCancellations && $summary->isNotEmpty())
                                <flux:button
                                    size="sm"
                                    variant="filled"
                                    icon="arrows-pointing-in"
                                    :disabled="$selectedGroups === []"
                                    wire:click="openUnifyModal"
                                >
                                    Unificar motivo ({{ count($selectedGroups) }})
                                </flux:button>
                            @endif
                        </div>
                    </div>

                    <x-internships.indicator-chart
                        :summary="$summary"
                        :metric="$metric"
                        :kind="$this->chartKind"
                        :active-group="$group"
                        :selectable-groups="$this->canUnifyCancellations"
                        :selected-groups="$selectedGroups"
                        :group-label-resolver="$this->groupLabel(...)"
                        :deadline-summary="in_array($metric, ['release_deadline', 'completion_deadline'], true) ? $this->deadlineSummary : null"
                        :grade-stats="in_array($metric, ['grade_distribution', 'grade_averages'], true) ? $this->gradeStats : null"
                        :processing-stats="in_array($metric, ['processing', 'addendum_processing'], true) ? $this->processingStats : null"
                    />
                </section>
            </div>
        @endif
    </x-loading-overlay>

    {{-- Modal de Exportação de Dados --}}
    <flux:modal name="export-indicators" wire:model.self="showExportModal" class="md:w-lg">
        <div class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg" level="2">Exportar dados</flux:heading>
                <flux:text class="mt-2">Os dados exportados respeitam o indicador <strong>{{ $this->activeMetricLabel }}</strong> e todos os filtros aplicados na tela.</flux:text>
            </div>

            <flux:radio.group wire:model.live="exportScope" label="Conteúdo do arquivo CSV">
                <flux:radio value="details" label="Registros detalhados" description="Lista cada estágio (ou documento) que compõe o indicador atual." />
                <flux:radio value="summary" label="Resumo consolidado por grupo" description="Exporta apenas a tabela agregada com os totais/médias por grupo." />
            </flux:radio.group>

            <div class="flex flex-wrap justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">Fechar</flux:button>
                </flux:modal.close>
                @if ($this->filterErrors->isEmpty())
                    <flux:button :href="$this->csvUrl" variant="primary" icon="arrow-down-tray" x-on:click="$wire.showExportModal = false">
                        Baixar CSV
                    </flux:button>
                @endif
            </div>
        </div>
    </flux:modal>

    {{-- Modal de Consulta de Registros --}}
    <flux:modal name="indicator-records" wire:model.self="showRecordsModal" class="w-[95vw] max-w-7xl">
        <div class="flex flex-col gap-5">
            <div class="pr-10">
                <flux:heading size="lg" level="2">Registros · {{ $this->activeMetricLabel }}</flux:heading>
                <flux:text class="mt-1">
                    @if ($this->selectedGroupLabel !== null)
                        Grupo filtrado: <strong>{{ $this->selectedGroupLabel }}</strong>
                    @else
                        Exibindo todos os grupos do recorte selecionado
                    @endif
                </flux:text>
            </div>

            @if ($showRecordsModal && $this->filterErrors->isEmpty())
                @php
                    $records = $this->records;
                @endphp
                <x-loading-overlay target="openGroupRecords,gotoPage,nextPage,previousPage" label="Carregando registros…" :dim-while-loading="true">
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <flux:text size="sm">{{ $records->total() }} {{ $records->total() === 1 ? 'registro encontrado' : 'registros encontrados' }}</flux:text>
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($group !== '')
                                    <flux:button size="sm" variant="outline" wire:click="openGroupRecords('')">Mostrar todos os grupos</flux:button>
                                @endif
                                @if ($this->filterErrors->isEmpty())
                                    <flux:button size="sm" variant="primary" icon="arrow-down-tray" :href="$this->modalCsvUrl">Baixar CSV deste recorte</flux:button>
                                @endif
                            </div>
                        </div>
                        @if ($records->isEmpty())
                            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-10 text-center dark:border-zinc-700">
                                <flux:text>Nenhum registro encontrado neste recorte.</flux:text>
                            </div>
                        @else
                            <flux:table :paginate="$records">
                                <flux:table.columns>
                                    @foreach ($this->headings as $heading)
                                        <flux:table.column>{{ $heading }}</flux:table.column>
                                    @endforeach
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach ($records as $record)
                                        @php
                                            $cells = $this->cells($record);
                                            $recordUrl = route('internships.show', $record->internship_id);
                                        @endphp
                                        <flux:table.row
                                            :key="'record-'.$metric.'-'.$record->internship_id.'-'.($record->document_id ?? '')"
                                            class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-700/40"
                                            x-on:click="Livewire.navigate(@js($recordUrl))"
                                        >
                                            <flux:table.cell class="whitespace-nowrap align-top">
                                                <flux:link :href="$recordUrl" wire:navigate x-on:click.stop>Nº {{ $record->internship_id }}</flux:link>
                                            </flux:table.cell>
                                            @foreach (array_slice($cells, 1) as $cell)
                                                <flux:table.cell class="max-w-sm whitespace-normal wrap-anywhere align-top">{{ $cell ?? '—' }}</flux:table.cell>
                                            @endforeach
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        @endif
                    </div>
                </x-loading-overlay>
            @endif

            <div class="flex justify-end">
                <flux:button type="button" variant="ghost" wire:click="closeRecordsModal">Fechar</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Modal de Explicação do Cálculo --}}
    <flux:modal name="indicator-methodology" wire:model.self="showMethodologyModal" class="w-full max-w-2xl">
        @php
            $guide = $this->methodology;
        @endphp
        <div class="flex flex-col gap-5">
            <div class="pr-10">
                <flux:heading size="lg" level="2">Como este indicador é calculado</flux:heading>
                <flux:text class="mt-1 font-medium text-zinc-700 dark:text-zinc-200">{{ $this->activeMetricLabel }}</flux:text>
            </div>

            <div class="space-y-4 text-sm text-zinc-700 dark:text-zinc-300">
                <p>{{ $guide['summary'] }}</p>

                <div class="space-y-1.5">
                    <flux:heading size="sm" level="3">Quais dados entram na conta</flux:heading>
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach ($guide['data_used'] as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="space-y-1.5">
                    <flux:heading size="sm" level="3">Como o resultado é obtido</flux:heading>
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach ($guide['how_calculated'] as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/70">
                    <flux:heading size="sm" level="3">Filtro de período</flux:heading>
                    <p class="mt-1">{{ $guide['period_filter'] }}</p>
                </div>

                @if ($guide['notes'] !== [])
                    <div class="space-y-1.5">
                        <flux:heading size="sm" level="3">Observações importantes</flux:heading>
                        <ul class="list-disc space-y-1 pl-5">
                            @foreach ($guide['notes'] as $note)
                                <li>{{ $note }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">Fechar</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>

    {{-- Modal de Unificação de Motivos de Cancelamento --}}
    @if ($this->canUnifyCancellations)
        <flux:modal name="unify-cancellation-reasons" wire:model.self="showUnifyModal" class="md:w-lg">
            <form wire:submit="unifySelectedReasons" class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg" level="2">Unificar motivos de cancelamento</flux:heading>
                    <flux:text class="mt-2">
                        Todos os pedidos de cancelamento dos {{ count($selectedGroups) }} {{ count($selectedGroups) === 1 ? 'grupo selecionado' : 'grupos selecionados' }} no recorte atual passarão a ser contabilizados sob o motivo informado abaixo. A justificativa original do pedido permanece preservada no histórico.
                    </flux:text>
                </div>

                <flux:field>
                    <flux:label required>Motivo consolidado para os indicadores</flux:label>
                    <flux:input wire:model="unifiedReason" maxlength="255" placeholder="Ex.: Desistência do discente" required />
                    <flux:error name="report_reason" />
                    <flux:error name="group_keys" />
                </flux:field>

                <div class="flex flex-wrap justify-end gap-3">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">Cancelar</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="unifySelectedReasons">
                        Salvar motivo unificado
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
