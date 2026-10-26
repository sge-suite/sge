@props(['steps', 'current', 'editable' => true, 'context' => 'formulário'])

@php
    $items = collect($steps);
    $required = $items->filter(fn (array $item): bool => $item['editable'] && ! $item['review'] && ! $item['optional']);
    $completed = $required->filter(fn (array $item): bool => $item['complete'])->count();
    $total = $required->count();
    $review = $items->first(fn (array $item): bool => $item['review']);
    $corrections = $items->contains(fn (array $item): bool => $item['needsCorrection']);
    $position = array_search($current, array_keys($steps), true) + 1;
@endphp

<div {{ $attributes->class('space-y-3') }}>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:text class="font-medium text-brand!">Etapa {{ $position }} de {{ count($steps) }} · {{ $steps[$current]['title'] }}</flux:text>
        <flux:text size="sm">{{ $editable ? 'O envio para análise acontece somente na revisão final.' : 'Consulta dos dados registrados.' }}</flux:text>
    </div>
    @if ($editable)
        <flux:text size="sm">{{ $completed }} de {{ $total }} {{ $total === 1 ? 'etapa obrigatória preenchida' : 'etapas obrigatórias preenchidas' }}</flux:text>
        <flux:progress wire:key="wizard-progress-{{ md5($context) }}-{{ $completed }}-{{ $total }}" :value="$completed" :max="max(1, $total)" :aria-valuenow="$completed" :aria-valuemax="max(1, $total)" style="--flux-progress-color: var(--color-brand)" :aria-label="'Preenchimento das etapas obrigatórias: '.$context" />
        <flux:text size="sm">
            @if ($corrections)
                Na correção, a contagem considera as etapas obrigatórias que podem ser editadas. Confira as orientações do Setor nas etapas “Corrigir”, mesmo quando os campos estão preenchidos.
            @elseif ($review['ready'] ?? false)
                As etapas obrigatórias estão preenchidas. Confira a revisão e confirme o envio quando estiver tudo certo.
            @else
                Os indicadores são conferidos ao salvar ou avançar. Etapas preenchidas ainda precisam ser revisadas antes do envio.
            @endif
        </flux:text>
    @endif
</div>
