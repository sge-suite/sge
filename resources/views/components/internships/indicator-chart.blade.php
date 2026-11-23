@props([
    'summary',
    'metric',
    'kind' => 'bar',
    'activeGroup' => '',
    'selectableGroups' => false,
    'selectedGroups' => [],
    'groupLabelResolver' => null,
    'deadlineSummary' => null,
    'gradeStats' => null,
    'processingStats' => null,
])

@php
    $resolveLabel = fn (string $name): string => is_callable($groupLabelResolver) ? $groupLabelResolver($name) : $name;
    $barMax = $metric === 'grade_averages'
        ? 10.0
        : (float) max(1, $summary->max(fn ($item) => property_exists($item, 'value') ? (float) ($item->value ?? 0) : (float) $item->total));

    $statusColors = [
        'completed' => '#10b981',
        'in_progress' => '#0ea5e9',
        'released' => '#14b8a6',
        'paused' => '#f59e0b',
        'awaiting_signatures' => '#8b5cf6',
        'pending_formalization' => '#6366f1',
        'pending_correction' => '#f97316',
        'cancelled' => '#f43f5e',
    ];

    $palette = [
        '#10b981', '#0ea5e9', '#f59e0b', '#8b5cf6', '#f43f5e',
        '#14b8a6', '#f97316', '#6366f1', '#ec4899', '#84cc16',
    ];

    $gradeColor = function (string $key): string {
        if ($key === 'missing') {
            return '#a1a1aa';
        }
        $n = (int) $key;

        return match (true) {
            $n >= 9 => '#10b981',
            $n >= 7 => '#0ea5e9',
            $n >= 6 => '#f59e0b',
            default => '#f43f5e',
        };
    };

    $fmt = fn (?float $v, int $decimals = 2): string => $v === null ? '—' : number_format($v, $decimals, ',', '.');
@endphp

@if ($summary->isEmpty())
    <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-12 text-center dark:border-zinc-700">
        <flux:icon.chart-bar class="mx-auto mb-3 size-8 text-zinc-400" />
        <flux:heading size="lg">Nenhum dado para este indicador</flux:heading>
        <flux:text class="mt-1">Ajuste o período ou os filtros selecionados para visualizar resultados.</flux:text>
    </div>
@else
    <div wire:key="chart-panel-{{ $metric }}-{{ $kind }}" class="space-y-6">
        {{-- KPI Cards específicos por indicador --}}
        @if ($gradeStats !== null)
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <flux:card class="p-4">
                    <flux:text size="sm">Média geral</flux:text>
                    <flux:heading size="lg" class="mt-1 tabular-nums">{{ $fmt($gradeStats['avg']) }}</flux:heading>
                    <flux:text size="xs" class="mt-0.5 text-zinc-500">Escala de 0 a 10</flux:text>
                </flux:card>
                <flux:card class="p-4">
                    <flux:text size="sm">Nota máxima</flux:text>
                    <flux:heading size="lg" class="mt-1 tabular-nums text-emerald-600 dark:text-emerald-400">{{ $fmt($gradeStats['max']) }}</flux:heading>
                </flux:card>
                <flux:card class="p-4">
                    <flux:text size="sm">Nota mínima</flux:text>
                    <flux:heading size="lg" class="mt-1 tabular-nums text-amber-600 dark:text-amber-400">{{ $fmt($gradeStats['min']) }}</flux:heading>
                </flux:card>
                <flux:card class="p-4">
                    <flux:text size="sm">Concluídos com nota</flux:text>
                    <flux:heading size="lg" class="mt-1 tabular-nums">{{ $gradeStats['measured'] }} <span class="text-sm font-normal text-zinc-500">/ {{ $gradeStats['total'] }}</span></flux:heading>
                    @if ($gradeStats['missing'] > 0)
                        <flux:text size="xs" class="mt-0.5 text-zinc-500">{{ $gradeStats['missing'] }} sem nota</flux:text>
                    @endif
                </flux:card>
            </div>
        @endif

        @if ($processingStats !== null)
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <flux:card class="p-4">
                    <flux:text size="sm">Tramitação média</flux:text>
                    <flux:heading size="lg" class="mt-1 tabular-nums">{{ $processingStats['avg'] === null ? '—' : $fmt($processingStats['avg'], 1).' dias' }}</flux:heading>
                    <flux:text size="xs" class="mt-0.5 text-zinc-500">{{ $processingStats['measured'] }} com dados · {{ $processingStats['missing'] }} sem dados</flux:text>
                </flux:card>
                <flux:card class="p-4">
                    <flux:text size="sm">Mediana</flux:text>
                    <flux:heading size="lg" class="mt-1 tabular-nums">{{ $processingStats['median'] === null ? '—' : $fmt($processingStats['median'], 1).' dias' }}</flux:heading>
                </flux:card>
                <flux:card class="p-4">
                    <flux:text size="sm">Menor tempo</flux:text>
                    <flux:heading size="lg" class="mt-1 tabular-nums text-emerald-600 dark:text-emerald-400">{{ $processingStats['min'] === null ? '—' : $fmt($processingStats['min'], 1).' dias' }}</flux:heading>
                </flux:card>
                <flux:card class="p-4">
                    <flux:text size="sm">Maior tempo</flux:text>
                    <flux:heading size="lg" class="mt-1 tabular-nums text-amber-600 dark:text-amber-400">{{ $processingStats['max'] === null ? '—' : $fmt($processingStats['max'], 1).' dias' }}</flux:heading>
                </flux:card>
            </div>
        @endif

        {{-- TIPO 1: DONUT (Situação geral dos estágios e Cancelamentos por motivo) --}}
        @if ($kind === 'donut')
            @php
                $totalSum = max(1, (int) $summary->sum('total'));
                $radius = 78;
                $circumference = 2 * M_PI * $radius;
                $offset = 0.0;
                $slices = [];
                foreach ($summary as $idx => $item) {
                    $label = $resolveLabel((string) $item->group_name);
                    $count = (int) $item->total;
                    $pct = round(100 * $count / $totalSum, 1);
                    $length = $circumference * ($count / $totalSum);
                    $color = $metric === 'statuses'
                        ? ($statusColors[$item->group_key] ?? $palette[$idx % count($palette)])
                        : ($item->group_key === 'missing' ? '#a1a1aa' : $palette[$idx % count($palette)]);
                    $slices[] = [
                        'key' => (string) $item->group_key,
                        'label' => $label,
                        'count' => $count,
                        'pct' => number_format($pct, 1, ',', '.'),
                        'length' => $length,
                        'offset' => $offset,
                        'color' => $color,
                    ];
                    $offset += $length;
                }
            @endphp
            <div
                x-data="{
                    hovered: null,
                    slices: @js(array_map(fn ($s) => ['key' => $s['key'], 'label' => $s['label'], 'count' => $s['count'], 'pct' => $s['pct'], 'color' => $s['color']], $slices)),
                    activeSlice() { return this.slices.find(s => s.key === this.hovered) ?? null; }
                }"
                class="grid gap-6 lg:grid-cols-[minmax(16rem,20rem)_minmax(0,1fr)] lg:items-start"
            >
                <flux:card class="flex flex-col items-center justify-center p-6">
                    <div class="relative flex size-56 items-center justify-center">
                        <svg viewBox="0 0 220 220" class="size-full -rotate-90 overflow-visible" role="img" aria-label="Gráfico de rosca do indicador">
                            <circle cx="110" cy="110" r="{{ $radius }}" fill="none" stroke="currentColor" stroke-width="26" class="text-zinc-100 dark:text-zinc-700/70" />
                            @foreach ($slices as $slice)
                                <circle
                                    cx="110"
                                    cy="110"
                                    r="{{ $radius }}"
                                    fill="none"
                                    stroke="{{ $slice['color'] }}"
                                    stroke-width="26"
                                    stroke-dasharray="{{ number_format($slice['length'], 3, '.', '') }} {{ number_format($circumference, 3, '.', '') }}"
                                    stroke-dashoffset="{{ number_format(-$slice['offset'], 3, '.', '') }}"
                                    class="cursor-pointer transition-all duration-150"
                                    x-bind:opacity="hovered === null || hovered === @js($slice['key']) ? 1 : 0.3"
                                    x-bind:stroke-width="hovered === @js($slice['key']) ? 32 : 26"
                                    x-on:mouseenter="hovered = @js($slice['key'])"
                                    x-on:mouseleave="hovered = null"
                                    wire:click="openGroupRecords(@js($slice['key']))"
                                />
                            @endforeach
                        </svg>
                        <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center px-6 text-center">
                            <div x-cloak x-show="activeSlice() !== null" class="flex flex-col items-center">
                                <span class="text-2xl font-bold tabular-nums text-zinc-900 dark:text-white" x-text="activeSlice()?.count ?? ''"></span>
                                <span class="text-xs font-semibold tabular-nums text-zinc-500 dark:text-zinc-400" x-text="(activeSlice()?.pct ?? '') + '%'"></span>
                                <span class="mt-1 line-clamp-2 max-w-28 text-[11px] leading-tight text-zinc-600 dark:text-zinc-300" x-text="activeSlice()?.label ?? ''"></span>
                            </div>
                            <div x-show="activeSlice() === null" class="flex flex-col items-center">
                                <span class="text-3xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $totalSum }}</span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $totalSum === 1 ? 'estágio' : 'estágios' }}</span>
                            </div>
                        </div>
                    </div>
                    <flux:text size="xs" class="mt-3 text-center text-zinc-500">Clique em uma fatia ou linha para ver os estágios</flux:text>
                </flux:card>

                <ul class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
                    @foreach ($slices as $slice)
                        @php
                            $canSelect = $selectableGroups && $slice['key'] !== 'missing';
                        @endphp
                        <li
                            wire:key="donut-row-{{ $metric }}-{{ $slice['key'] }}"
                            wire:click="openGroupRecords(@js($slice['key']))"
                            x-on:mouseenter="hovered = @js($slice['key'])"
                            x-on:mouseleave="hovered = null"
                            x-bind:class="hovered === @js($slice['key']) ? 'bg-zinc-50 dark:bg-zinc-700/40' : ''"
                            class="group flex cursor-pointer items-center justify-between gap-4 px-4 py-3 transition-colors"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                @if ($selectableGroups)
                                    <div class="shrink-0" x-on:click.stop>
                                        @if ($canSelect)
                                            <flux:checkbox wire:model.live="selectedGroups" :value="$slice['key']" aria-label="Selecionar motivo {{ $slice['label'] }}" />
                                        @else
                                            <span class="inline-block size-4" aria-hidden="true"></span>
                                        @endif
                                    </div>
                                @endif
                                <span class="size-3 shrink-0 rounded-full" style="background-color: {{ $slice['color'] }}"></span>
                                <span class="wrap-anywhere text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $slice['label'] }}</span>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <flux:text size="sm" class="tabular-nums">
                                    <strong class="text-zinc-900 dark:text-zinc-100">{{ $slice['count'] }}</strong>
                                    <span class="text-zinc-500">({{ $slice['pct'] }}%)</span>
                                </flux:text>
                                <flux:icon.chevron-right variant="micro" class="text-zinc-400 transition-transform group-hover:translate-x-0.5 group-hover:text-zinc-600 dark:group-hover:text-zinc-200" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>

        {{-- TIPO 2: LINHA COM ÁREA (Tempo médio de tramitação / mês e Aditivos / mês) --}}
        @elseif ($kind === 'line')
            @php
                $points = [];
                $maxVal = max(1.0, (float) $summary->max(fn ($item) => (float) ($item->value ?? 0)));
                $yMax = ceil($maxVal / 5) * 5;
                if ($yMax < 5) {
                    $yMax = max(1.0, ceil($maxVal));
                }

                $svgW = 720;
                $svgH = 250;
                $padL = 44;
                $padR = 24;
                $padT = 22;
                $padB = 42;
                $plotW = $svgW - $padL - $padR;
                $plotH = $svgH - $padT - $padB;
                $countPts = $summary->count();

                foreach ($summary->values() as $idx => $item) {
                    $label = $resolveLabel((string) $item->group_name);
                    $val = $item->value !== null ? (float) $item->value : null;
                    $x = $countPts === 1
                        ? $padL + ($plotW / 2)
                        : $padL + ($plotW * $idx / ($countPts - 1));
                    $y = $val !== null
                        ? $padT + $plotH - ($plotH * ($val / $yMax))
                        : null;

                    $points[] = [
                        'key' => (string) $item->group_key,
                        'label' => $label,
                        'value' => $val,
                        'display' => $val === null ? 'Sem média' : number_format($val, 2, ',', '.').' dias',
                        'measured' => (int) ($item->measured ?? 0),
                        'missing' => (int) $item->total - (int) ($item->measured ?? 0),
                        'total' => (int) $item->total,
                        'x' => round($x, 1),
                        'y' => $y !== null ? round($y, 1) : null,
                    ];
                }

                $validPoints = array_values(array_filter($points, fn ($p) => $p['y'] !== null));
                $linePath = '';
                $areaPath = '';
                if (count($validPoints) >= 1) {
                    $segments = [];
                    foreach ($validPoints as $i => $p) {
                        $segments[] = ($i === 0 ? 'M ' : 'L ').$p['x'].' '.$p['y'];
                    }
                    $linePath = implode(' ', $segments);
                    $first = $validPoints[0];
                    $last = $validPoints[count($validPoints) - 1];
                    $baseline = $padT + $plotH;
                    $areaPath = $linePath." L {$last['x']} {$baseline} L {$first['x']} {$baseline} Z";
                }
            @endphp

            <div
                x-data="{
                    hovered: null,
                    points: @js($points),
                    activePoint() { return this.points.find(p => p.key === this.hovered) ?? null; }
                }"
            >
                <flux:card class="p-5">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <flux:text size="sm" class="font-medium">Tempo médio (dias corridos) por mês</flux:text>
                        <div class="flex h-7 items-center text-xs">
                            <span
                                x-cloak
                                x-show="activePoint() !== null"
                                class="inline-flex items-center gap-2 rounded-md bg-zinc-900 px-2.5 py-1 font-medium text-white dark:bg-zinc-100 dark:text-zinc-900"
                            >
                                <span x-text="activePoint()?.label ?? ''"></span>
                                <span>·</span>
                                <span class="tabular-nums" x-text="activePoint()?.display ?? ''"></span>
                                <span class="opacity-75" x-text="'(' + (activePoint()?.measured ?? 0) + ' com dados)'"></span>
                            </span>
                            <flux:text x-show="activePoint() === null" size="xs" class="text-zinc-500">Passe o mouse ou clique em um ponto para ver os registros</flux:text>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <svg viewBox="0 0 {{ $svgW }} {{ $svgH }}" class="h-auto w-full min-w-[36rem]" role="img" aria-label="Gráfico de linha do tempo médio de tramitação por mês">
                            <defs>
                                <linearGradient id="lineArea-{{ $metric }}" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" style="stop-color: var(--color-brand)" stop-opacity="0.25" />
                                    <stop offset="100%" style="stop-color: var(--color-brand)" stop-opacity="0.02" />
                                </linearGradient>
                            </defs>

                            {{-- Gridlines horizontais --}}
                            @foreach ([0, 0.25, 0.5, 0.75, 1] as $step)
                                @php
                                    $gy = round($padT + $plotH - ($plotH * $step), 1);
                                    $gVal = round($yMax * $step, 1);
                                @endphp
                                <line x1="{{ $padL }}" y1="{{ $gy }}" x2="{{ $svgW - $padR }}" y2="{{ $gy }}" stroke="currentColor" stroke-dasharray="3 3" class="text-zinc-200 dark:text-zinc-700" />
                                <text x="{{ $padL - 8 }}" y="{{ $gy + 4 }}" text-anchor="end" fill="currentColor" class="fill-zinc-500 text-[11px] tabular-nums dark:fill-zinc-400">{{ $gVal }}d</text>
                            @endforeach

                            {{-- Área e linha --}}
                            @if ($areaPath !== '' && count($validPoints) > 1)
                                <path d="{{ $areaPath }}" fill="url(#lineArea-{{ $metric }})" />
                                <path d="{{ $linePath }}" fill="none" stroke="var(--color-brand)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
                            @endif

                            {{-- Pontos interativos e rótulos no eixo X --}}
                            @foreach ($points as $pt)
                                <text x="{{ $pt['x'] }}" y="{{ $svgH - 12 }}" text-anchor="middle" fill="currentColor" class="fill-zinc-600 text-[11px] font-medium dark:fill-zinc-300">{{ $pt['label'] }}</text>

                                @if ($pt['y'] !== null)
                                    <g
                                        class="cursor-pointer"
                                        x-on:mouseenter="hovered = @js($pt['key'])"
                                        x-on:mouseleave="hovered = null"
                                        wire:click="openGroupRecords(@js($pt['key']))"
                                    >
                                        <circle cx="{{ $pt['x'] }}" cy="{{ $pt['y'] }}" r="16" fill="transparent" />
                                        <circle
                                            cx="{{ $pt['x'] }}"
                                            cy="{{ $pt['y'] }}"
                                            r="5"
                                            fill="var(--color-brand)"
                                            stroke="white"
                                            stroke-width="2"
                                            class="transition-all duration-150 dark:stroke-zinc-800"
                                            x-bind:r="hovered === @js($pt['key']) ? 7 : 5"
                                        />
                                        <text
                                            x="{{ $pt['x'] }}"
                                            y="{{ max(14, $pt['y'] - 11) }}"
                                            text-anchor="middle"
                                            fill="currentColor"
                                            class="fill-zinc-700 text-[10px] font-semibold tabular-nums dark:fill-zinc-200"
                                        >{{ number_format($pt['value'], 1, ',', '.') }}d</text>
                                    </g>
                                @endif
                            @endforeach
                        </svg>
                    </div>
                </flux:card>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($points as $pt)
                    <button
                        type="button"
                        wire:key="line-row-{{ $metric }}-{{ $pt['key'] }}"
                        wire:click="openGroupRecords(@js($pt['key']))"
                        class="group flex items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 text-left transition-colors hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-600 dark:hover:bg-zinc-700/50"
                    >
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $pt['label'] }}</div>
                            <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $pt['measured'] }} com dados · {{ $pt['missing'] }} sem dados
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1.5">
                            <span class="text-sm font-semibold tabular-nums text-brand">{{ $pt['display'] }}</span>
                            <flux:icon.chevron-right variant="micro" class="text-zinc-400 transition-transform group-hover:translate-x-0.5 group-hover:text-zinc-600 dark:group-hover:text-zinc-200" />
                        </div>
                    </button>
                @endforeach
            </div>

        {{-- TIPO 3: COLUNAS COLORIDAS / HISTOGRAMA (Distribuição das notas finais) --}}
        @elseif ($kind === 'column')
            @php
                $totalGrades = max(1, (int) $summary->sum('total'));
                $cols = [];
                foreach ($summary as $item) {
                    $label = $resolveLabel((string) $item->group_name);
                    $count = (int) $item->total;
                    $pct = round(100 * $count / $totalGrades, 1);
                    $barPct = min(100, round(100 * $count / $barMax, 1));
                    $cols[] = [
                        'key' => (string) $item->group_key,
                        'label' => $label,
                        'count' => $count,
                        'pct' => number_format($pct, 1, ',', '.'),
                        'barPct' => max($count > 0 ? 6 : 0, $barPct),
                        'color' => $gradeColor((string) $item->group_key),
                    ];
                }
            @endphp

            <div
                x-data="{
                    hovered: null,
                    cols: @js($cols),
                    activeCol() { return this.cols.find(c => c.key === this.hovered) ?? null; }
                }"
            >
                <flux:card class="p-5">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <flux:text size="sm" class="font-medium">Estágios concluídos por faixa de nota</flux:text>
                        <div class="flex h-7 items-center text-xs">
                            <span
                                x-cloak
                                x-show="activeCol() !== null"
                                class="inline-flex items-center gap-2 rounded-md bg-zinc-900 px-2.5 py-1 font-medium text-white dark:bg-zinc-100 dark:text-zinc-900"
                            >
                                <span class="size-2 rounded-full" x-bind:style="'background-color:' + (activeCol()?.color ?? '#a1a1aa')"></span>
                                <span x-text="activeCol()?.label ?? ''"></span>
                                <span>·</span>
                                <span class="tabular-nums" x-text="(activeCol()?.count ?? 0) + ((activeCol()?.count ?? 0) === 1 ? ' estágio' : ' estágios') + ' (' + (activeCol()?.pct ?? '0,0') + '%)'"></span>
                            </span>
                            <flux:text x-show="activeCol() === null" size="xs" class="text-zinc-500">Passe o mouse ou clique em uma coluna para ver os registros</flux:text>
                        </div>
                    </div>

                    <div class="overflow-x-auto pb-2">
                        <div class="grid min-w-[30rem] auto-cols-fr grid-flow-col items-end gap-3 pt-4" style="min-height: 14rem" role="list" aria-label="Histograma de notas finais">
                            @foreach ($cols as $col)
                                @php
                                    $isSelected = $activeGroup === $col['key'];
                                @endphp
                                <button
                                    type="button"
                                    role="listitem"
                                    wire:key="col-chart-{{ $metric }}-{{ $col['key'] }}"
                                    wire:click="openGroupRecords(@js($col['key']))"
                                    x-on:mouseenter="hovered = @js($col['key'])"
                                    x-on:mouseleave="hovered = null"
                                    aria-label="{{ $col['label'] }}: {{ $col['count'] }} ({{ $col['pct'] }}%)"
                                    class="group flex h-48 flex-col items-center justify-end gap-2 rounded-lg p-2 text-center transition-colors hover:bg-zinc-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand dark:hover:bg-zinc-700/60 {{ $isSelected ? 'bg-zinc-100 ring-1 ring-brand dark:bg-zinc-700/60' : '' }}"
                                >
                                    <span class="text-xs font-semibold tabular-nums text-zinc-700 dark:text-zinc-200">{{ $col['count'] }}</span>
                                    <div class="flex w-full max-w-14 flex-1 items-end justify-center rounded-t-md bg-zinc-100 dark:bg-zinc-700/60">
                                        <div
                                            class="w-full rounded-t-md transition-all duration-150"
                                            style="height: {{ $col['barPct'] }}%; background-color: {{ $col['color'] }}"
                                            x-bind:style="'height: {{ $col['barPct'] }}%; background-color: {{ $col['color'] }}; opacity: ' + (hovered === null || hovered === @js($col['key']) ? '1' : '0.45')"
                                        ></div>
                                    </div>
                                    <span class="line-clamp-2 w-full text-xs font-medium text-zinc-600 dark:text-zinc-300">{{ $col['label'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                </flux:card>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($cols as $col)
                    <button
                        type="button"
                        wire:key="col-row-{{ $metric }}-{{ $col['key'] }}"
                        wire:click="openGroupRecords(@js($col['key']))"
                        class="group flex items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 text-left transition-colors hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-600 dark:hover:bg-zinc-700/50"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="size-3 shrink-0 rounded-full" style="background-color: {{ $col['color'] }}"></span>
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $col['label'] }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $col['pct'] }}% dos concluídos</div>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1.5">
                            <span class="text-sm font-semibold tabular-nums text-zinc-900 dark:text-zinc-100">{{ $col['count'] }}</span>
                            <flux:icon.chevron-right variant="micro" class="text-zinc-400 transition-transform group-hover:translate-x-0.5 group-hover:text-zinc-600 dark:group-hover:text-zinc-200" />
                        </div>
                    </button>
                @endforeach
            </div>

        {{-- TIPO 4: EMPILHADO + DONUT CONSOLIDADO (Encaminhamento e Conclusão no prazo) --}}
        @elseif ($kind === 'stacked')
            @php
                $onTimeTotal = (int) ($deadlineSummary->on_time ?? 0);
                $lateTotal = (int) ($deadlineSummary->late ?? 0);
                $unknownTotal = (int) ($deadlineSummary->unknown ?? 0);
                $allTotal = max(1, $onTimeTotal + $lateTotal + $unknownTotal);
                $onTimeOverallPct = round(100 * $onTimeTotal / $allTotal, 1);
                $lateOverallPct = round(100 * $lateTotal / $allTotal, 1);
                $unknownOverallPct = max(0, round(100 - $onTimeOverallPct - $lateOverallPct, 1));

                $dRadius = 64;
                $dCirc = 2 * M_PI * $dRadius;
                $dSlices = [
                    ['key' => 'on_time', 'label' => 'No prazo', 'count' => $onTimeTotal, 'pct' => number_format($onTimeOverallPct, 1, ',', '.'), 'color' => '#10b981'],
                    ['key' => 'late', 'label' => 'Fora do prazo', 'count' => $lateTotal, 'pct' => number_format($lateOverallPct, 1, ',', '.'), 'color' => '#f59e0b'],
                    ['key' => 'unknown', 'label' => 'Sem dados', 'count' => $unknownTotal, 'pct' => number_format($unknownOverallPct, 1, ',', '.'), 'color' => '#a1a1aa'],
                ];
                $dOffset = 0.0;
                foreach ($dSlices as &$ds) {
                    $ds['length'] = $dCirc * ($ds['count'] / $allTotal);
                    $ds['offset'] = $dOffset;
                    $dOffset += $ds['length'];
                }
                unset($ds);
            @endphp

            <div
                x-data="{
                    hovered: null,
                    slices: @js(array_map(fn ($s) => ['key' => $s['key'], 'label' => $s['label'], 'count' => $s['count'], 'pct' => $s['pct'], 'color' => $s['color']], $dSlices)),
                    activeSlice() { return this.slices.find(s => s.key === this.hovered) ?? null; }
                }"
                class="grid gap-4 lg:grid-cols-[15rem_minmax(0,1fr)] lg:items-center"
            >
                <flux:card class="flex flex-col items-center justify-center p-5">
                    <div class="relative flex size-44 items-center justify-center">
                        <svg viewBox="0 0 180 180" class="size-full -rotate-90 overflow-visible" role="img" aria-label="Consolidado de prazo">
                            <circle cx="90" cy="90" r="{{ $dRadius }}" fill="none" stroke="currentColor" stroke-width="22" class="text-zinc-100 dark:text-zinc-700/70" />
                            @foreach ($dSlices as $ds)
                                @if ($ds['count'] > 0)
                                    <circle
                                        cx="90"
                                        cy="90"
                                        r="{{ $dRadius }}"
                                        fill="none"
                                        stroke="{{ $ds['color'] }}"
                                        stroke-width="22"
                                        stroke-dasharray="{{ number_format($ds['length'], 3, '.', '') }} {{ number_format($dCirc, 3, '.', '') }}"
                                        stroke-dashoffset="{{ number_format(-$ds['offset'], 3, '.', '') }}"
                                        class="cursor-pointer transition-all duration-150"
                                        x-bind:opacity="hovered === null || hovered === @js($ds['key']) ? 1 : 0.3"
                                        x-bind:stroke-width="hovered === @js($ds['key']) ? 26 : 22"
                                        x-on:mouseenter="hovered = @js($ds['key'])"
                                        x-on:mouseleave="hovered = null"
                                    />
                                @endif
                            @endforeach
                        </svg>
                        <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                            <div x-cloak x-show="activeSlice() !== null" class="flex flex-col items-center">
                                <span class="text-xl font-bold tabular-nums text-zinc-900 dark:text-white" x-text="(activeSlice()?.pct ?? '') + '%'"></span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400" x-text="activeSlice()?.label ?? ''"></span>
                            </div>
                            <div x-show="activeSlice() === null" class="flex flex-col items-center">
                                <span class="text-2xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ number_format($onTimeOverallPct, 1, ',', '.') }}%</span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">No prazo</span>
                            </div>
                        </div>
                    </div>
                </flux:card>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div x-on:mouseenter="hovered = 'on_time'" x-on:mouseleave="hovered = null">
                        <flux:card class="h-full p-4 transition-colors" x-bind:class="hovered === 'on_time' ? 'ring-1 ring-emerald-500/60' : ''">
                            <div class="flex items-center gap-2">
                                <span class="size-2.5 rounded-full bg-emerald-500"></span>
                                <flux:text size="sm">No prazo</flux:text>
                            </div>
                            <flux:heading size="xl" class="mt-2 tabular-nums">{{ $onTimeTotal }}</flux:heading>
                            <flux:text size="xs" class="mt-0.5 tabular-nums text-zinc-500">{{ number_format($onTimeOverallPct, 1, ',', '.') }}% do recorte</flux:text>
                        </flux:card>
                    </div>
                    <div x-on:mouseenter="hovered = 'late'" x-on:mouseleave="hovered = null">
                        <flux:card class="h-full p-4 transition-colors" x-bind:class="hovered === 'late' ? 'ring-1 ring-amber-500/60' : ''">
                            <div class="flex items-center gap-2">
                                <span class="size-2.5 rounded-full bg-amber-500"></span>
                                <flux:text size="sm">Fora do prazo</flux:text>
                            </div>
                            <flux:heading size="xl" class="mt-2 tabular-nums">{{ $lateTotal }}</flux:heading>
                            <flux:text size="xs" class="mt-0.5 tabular-nums text-zinc-500">{{ number_format($lateOverallPct, 1, ',', '.') }}% do recorte</flux:text>
                        </flux:card>
                    </div>
                    <div x-on:mouseenter="hovered = 'unknown'" x-on:mouseleave="hovered = null">
                        <flux:card class="h-full p-4 transition-colors" x-bind:class="hovered === 'unknown' ? 'ring-1 ring-zinc-400/60' : ''">
                            <div class="flex items-center gap-2">
                                <span class="size-2.5 rounded-full bg-zinc-400 dark:bg-zinc-500"></span>
                                <flux:text size="sm">Sem dados</flux:text>
                            </div>
                            <flux:heading size="xl" class="mt-2 tabular-nums">{{ $unknownTotal }}</flux:heading>
                            <flux:text size="xs" class="mt-0.5 tabular-nums text-zinc-500">{{ number_format($unknownOverallPct, 1, ',', '.') }}% do recorte</flux:text>
                        </flux:card>
                    </div>
                </div>
            </div>

            <div class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2 text-xs font-medium text-zinc-600 dark:text-zinc-300">
                    <div class="flex flex-wrap items-center gap-4">
                        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-emerald-500"></span>No prazo</span>
                        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-amber-500"></span>Fora do prazo</span>
                        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-zinc-400 dark:bg-zinc-500"></span>Sem dados</span>
                    </div>
                    <span class="font-normal text-zinc-500 dark:text-zinc-400">Clique em um curso para consultar os estágios</span>
                </div>

                <ul class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
                    @foreach ($summary as $item)
                        @php
                            $label = $resolveLabel((string) $item->group_name);
                            $total = max(1, (int) $item->total);
                            $onTimePct = round(100 * (int) $item->on_time / $total, 1);
                            $latePct = round(100 * (int) $item->late / $total, 1);
                            $unknownPct = max(0, round(100 - $onTimePct - $latePct, 1));
                        @endphp
                        <li
                            wire:key="stacked-{{ $metric }}-{{ $item->group_key }}"
                            wire:click="openGroupRecords(@js($item->group_key))"
                            class="group grid cursor-pointer gap-3 px-4 py-3.5 transition-colors hover:bg-zinc-50 sm:grid-cols-[minmax(0,1fr)_minmax(14rem,1.4fr)_auto] sm:items-center dark:hover:bg-zinc-700/40"
                        >
                            <div class="min-w-0">
                                <span class="wrap-anywhere text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $label }}</span>
                                <flux:text size="sm">{{ $item->total }} {{ (int) $item->total === 1 ? 'estágio' : 'estágios' }}</flux:text>
                            </div>
                            <div class="space-y-1.5">
                                <div class="flex h-3 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700" role="img" aria-label="{{ $label }}: {{ $item->on_time }} no prazo, {{ $item->late }} fora do prazo, {{ $item->unknown }} sem dados">
                                    @if ((int) $item->on_time > 0)
                                        <div class="h-full bg-emerald-500 transition-all" style="width: {{ $onTimePct }}%" title="No prazo: {{ $item->on_time }} ({{ number_format($onTimePct, 1, ',', '.') }}%)"></div>
                                    @endif
                                    @if ((int) $item->late > 0)
                                        <div class="h-full bg-amber-500 transition-all" style="width: {{ $latePct }}%" title="Fora do prazo: {{ $item->late }} ({{ number_format($latePct, 1, ',', '.') }}%)"></div>
                                    @endif
                                    @if ((int) $item->unknown > 0)
                                        <div class="h-full bg-zinc-400 transition-all dark:bg-zinc-500" style="width: {{ $unknownPct }}%" title="Sem dados: {{ $item->unknown }} ({{ number_format($unknownPct, 1, ',', '.') }}%)"></div>
                                    @endif
                                </div>
                                <flux:text size="sm" class="tabular-nums">
                                    {{ $item->on_time }} no prazo ({{ number_format($onTimePct, 1, ',', '.') }}%) · {{ $item->late }} fora · {{ $item->unknown }} sem dados
                                </flux:text>
                            </div>
                            <div class="hidden justify-end sm:flex">
                                <flux:icon.chevron-right variant="micro" class="text-zinc-400 transition-transform group-hover:translate-x-0.5 group-hover:text-zinc-600 dark:group-hover:text-zinc-200" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>

        {{-- TIPO 5: BARRAS HORIZONTAIS (Cursos, Orientadores, Concedentes, Supervisores, Notas médias) --}}
        @else
            <div class="space-y-2">
                <div class="flex justify-end">
                    <flux:text size="xs" class="text-zinc-500">Clique em uma linha para consultar os estágios</flux:text>
                </div>
                <ul class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
                    @foreach ($summary as $item)
                        @php
                            $label = $resolveLabel((string) $item->group_name);
                            $value = property_exists($item, 'value') ? $item->value : $item->total;
                            $pct = $value === null ? 0 : min(100, round(100 * (float) $value / $barMax, 1));
                            $barColor = $metric === 'grade_averages'
                                ? ($value === null ? '#a1a1aa' : ((float) $value >= 8 ? '#10b981' : ((float) $value >= 6 ? '#0ea5e9' : '#f59e0b')))
                                : null;
                        @endphp
                        <li
                            wire:key="bar-{{ $metric }}-{{ $item->group_key }}"
                            wire:click="openGroupRecords(@js($item->group_key))"
                            class="group grid cursor-pointer gap-3 px-4 py-3.5 transition-colors hover:bg-zinc-50 sm:grid-cols-[minmax(0,1.1fr)_minmax(14rem,1.4fr)_auto] sm:items-center dark:hover:bg-zinc-700/40"
                        >
                            <div class="min-w-0">
                                <span class="wrap-anywhere text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $label }}</span>
                            </div>
                            <div class="space-y-1.5">
                                @if ($value !== null)
                                    <div aria-hidden="true" class="h-3 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700">
                                        <div
                                            class="h-full rounded-full transition-all {{ $barColor === null ? 'bg-brand' : '' }}"
                                            style="width: {{ max((float) $value > 0 ? 3 : 0, $pct) }}%{{ $barColor !== null ? '; background-color: '.$barColor : '' }}"
                                        ></div>
                                    </div>
                                @endif
                                <flux:text size="sm" class="tabular-nums">
                                    @if ($metric === 'grade_averages')
                                        {{ $value === null ? 'Sem média' : number_format((float) $value, 2, ',', '.').' de 10' }} · {{ $item->measured }} com nota · {{ $item->total - $item->measured }} sem nota
                                    @elseif (in_array($metric, ['courses', 'supervisors'], true))
                                        {{ $value }} {{ (int) $value === 1 ? 'estudante único' : 'estudantes únicos' }} · {{ $item->total }} {{ (int) $item->total === 1 ? 'estágio' : 'estágios' }}
                                    @elseif ($metric === 'course_parties')
                                        {{ $value }} {{ (int) $value === 1 ? 'concedente' : 'concedentes' }} · {{ $item->total }} {{ (int) $item->total === 1 ? 'estágio' : 'estágios' }}
                                    @else
                                        {{ $value }} {{ (int) $value === 1 ? 'estágio' : 'estágios' }}
                                    @endif
                                </flux:text>
                            </div>
                            <div class="hidden justify-end sm:flex">
                                <flux:icon.chevron-right variant="micro" class="text-zinc-400 transition-transform group-hover:translate-x-0.5 group-hover:text-zinc-600 dark:group-hover:text-zinc-200" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endif
