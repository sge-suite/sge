@props(['steps', 'current', 'label' => 'Etapas do formulário', 'model' => 'navigationStep', 'action' => 'goTo'])

@php
    $items = collect($steps)->map(function (array $step): array {
        $state = match (true) {
            ! $step['editable'] => 'readonly',
            $step['review'] => $step['ready'] ? 'review' : 'waiting',
            $step['needsCorrection'] => 'correction',
            ! $step['complete'] => 'incomplete',
            $step['optional'] => 'optional',
            default => 'complete',
        };

        return $step + ['state' => $state, 'label' => match ($state) {
            'readonly' => 'Consulta', 'review' => 'Revisar', 'waiting' => 'Aguardando preenchimento',
            'correction' => 'Corrigir', 'incomplete' => 'Incompleta', 'optional' => 'Opcional', default => 'Preenchida',
        }, 'color' => match ($state) {
            'complete' => 'green', 'correction' => 'amber', 'review' => 'blue', default => 'zinc',
        }];
    });
    $ids = $items->keys()->all();
    $options = $items->mapWithKeys(fn (array $item, int $id): array => [
        $id => (array_search($id, $ids, true) + 1).'. '.$item['title'].' · '.$item['label'],
    ])->all();
@endphp

<nav aria-label="{{ $label }}" {{ $attributes->class('lg:sticky lg:top-6') }}>
    <div class="lg:hidden">
        <x-select label="Ir para uma etapa" wire:model.live="{{ $model }}" :value="$current" :options="$options" />
    </div>
    <ol class="hidden space-y-1 lg:block">
        @foreach ($items as $id => $item)
            <li wire:key="wizard-step-{{ $id }}">
                <flux:button type="button" variant="ghost" wire:click="{{ $action }}({{ $id }})" wire:loading.attr="disabled" :aria-current="$current === $id ? 'step' : null"
                    @class(['h-auto! w-full justify-start! whitespace-normal! py-2.5! text-left', 'bg-brand/10! text-brand!' => $current === $id])>
                    <span class="flex w-full min-w-0 items-start gap-3">
                        <span aria-hidden="true" class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full border border-current/20 text-xs">
                            @if ($item['state'] === 'complete')<flux:icon.check variant="micro" />@else{{ $loop->iteration }}@endif
                        </span>
                        <span class="min-w-0 space-y-1">
                            <span class="block wrap-break-word">{{ $item['title'] }}</span>
                            <flux:badge size="sm" :color="$item['color']">{{ $item['label'] }}</flux:badge>
                        </span>
                    </span>
                </flux:button>
            </li>
        @endforeach
    </ol>
</nav>
