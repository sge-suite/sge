@props([
    'values' => [],
    'model' => 'values.address',
    'errorPrefix' => 'address',
    'namePrefix' => 'address',
    'cityModel' => 'selectedCityId',
    'required' => true,
    'selectedCityId' => null,
    'campusId' => null,
    'selectionKey' => 'address-city',
    'cityMessage' => '',
    'cityHintPlacement' => 'top',
    'cepLookupChanges' => [],
    'personalData' => false,
])

@php
    $nameParts = explode('.', $namePrefix);
    $htmlName = array_shift($nameParts).($nameParts === [] ? '' : '['.implode('][', $nameParts).']');
    $fieldError = fn (string $field): string => $errors->first($errorPrefix.'.'.$field) ?: $errors->first($model.'.'.$field);
@endphp

@if ($cityHintPlacement === 'top')
<flux:text class="mt-1" role="status">{{ $cityMessage !== '' ? $cityMessage : 'Selecione a UF e digite pelo menos 2 caracteres para buscar a cidade.' }}</flux:text>
@endif
<div class="mt-5 grid items-start gap-5 sm:grid-cols-2">
    <div class="sm:col-span-2 flex flex-col items-start gap-3">
        <flux:input :name="$htmlName.'[zip_code]'" :error:name="$errorPrefix.'.zip_code'" :error:message="$fieldError('zip_code')" label="CEP" wire:model="{{ $model }}.zip_code" :value="$values['zip_code'] ?? ''" :invalid="$fieldError('zip_code') !== ''" maxlength="10" autocomplete="postal-code" inputmode="numeric" placeholder="00000-000" :required="$required" />
        <flux:button type="button" size="sm" icon="magnifying-glass" wire:click="lookupCep" wire:loading.attr="disabled" wire:target="lookupCep">Buscar CEP</flux:button>
        <flux:text wire:loading wire:target="lookupCep" role="status">Consultando CEP…</flux:text>
    </div>
    @if ($cityHintPlacement === 'selection')
        <flux:text class="sm:col-span-2" role="status">{{ $cityMessage !== '' ? $cityMessage : 'Selecione a UF e digite pelo menos 2 caracteres para buscar a cidade.' }}</flux:text>
    @endif
    <livewire:cities.select wire:model.live="{{ $cityModel }}" :selected-city-id="$selectedCityId" :campus-id="$campusId" :personal-data="$personalData" :key="$selectionKey" :city-error="$fieldError('city_id')" :field-name="$htmlName.'[city_id]'" :required="$required" />
    <flux:input :name="$htmlName.'[street]'" :error:name="$errorPrefix.'.street'" :error:message="$fieldError('street')" label="Logradouro" wire:model="{{ $model }}.street" :value="$values['street'] ?? ''" :invalid="$fieldError('street') !== ''" maxlength="255" autocomplete="address-line1" :required="$required" />
    <flux:input.group :name="$htmlName.'[number]'" :error:name="$errorPrefix.'.number'" :error:message="$fieldError('number')" label="Número" class="min-w-0">
        <flux:input :name="$htmlName.'[number]'" wire:model="{{ $model }}.number" :value="$values['number'] ?? ''" :invalid="$fieldError('number') !== ''" maxlength="255" :required="$required" />
        <flux:input.group.suffix size="sm" class="px-1">
            <flux:button type="button" size="sm" variant="ghost" wire:click="$set('{{ $model }}.number', 'S/N')">Sem número</flux:button>
        </flux:input.group.suffix>
    </flux:input.group>
    <flux:input :name="$htmlName.'[neighborhood]'" :error:name="$errorPrefix.'.neighborhood'" :error:message="$fieldError('neighborhood')" label="Bairro" wire:model="{{ $model }}.neighborhood" :value="$values['neighborhood'] ?? ''" :invalid="$fieldError('neighborhood') !== ''" maxlength="255" :required="$required" />
</div>

    <flux:modal
        :name="$selectionKey.'-cep-preview'"
        wire:model.self="showCepLookupPreview"
        class="w-[calc(100vw-2rem)] max-h-[calc(100dvh-2rem)] max-w-5xl! overflow-hidden"
    >
        <div class="flex max-h-[calc(100dvh-5rem)] min-h-0 flex-col">
            <div class="shrink-0">
                <flux:heading size="lg" level="2">Revise o endereço encontrado</flux:heading>
                <flux:text class="mt-2">Nada será alterado no formulário até você confirmar. O número e os valores que não vieram da consulta serão mantidos.</flux:text>
            </div>

            @php($cepLookupPreviewChanges = $cepLookupChanges)

            <div class="scroll-fade-y mt-5 min-h-0 flex-1 overflow-y-auto overscroll-contain pr-2">
                <div class="space-y-5">
                    <section aria-labelledby="{{ $selectionKey }}-cep-changes-heading">
                        <flux:heading id="{{ $selectionKey }}-cep-changes-heading" level="3">Alterações previstas</flux:heading>
                        <flux:text size="sm" class="mt-1">Confira o valor atual e o valor que será aplicado.</flux:text>

                        @if ($cepLookupPreviewChanges === [])
                            <flux:callout class="mt-3" icon="information-circle">Os dados encontrados já correspondem ao formulário. Nenhum campo será alterado.</flux:callout>
                        @else
                            <div class="mt-3">
                                <div class="grid gap-3 pb-1 lg:grid-cols-2">
                                    @foreach ($cepLookupPreviewChanges as $change)
                                        <div wire:key="{{ $selectionKey }}-cep-change-{{ $loop->index }}" class="grid gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] sm:items-center">
                                            <div class="min-w-0">
                                                <flux:text size="sm">{{ $change['label'] }}</flux:text>
                                                <flux:text size="xs" class="mt-1">Valor atual</flux:text>
                                                <p class="mt-0.5 wrap-break-word text-sm text-zinc-600 dark:text-zinc-300">{{ $change['current'] }}</p>
                                            </div>
                                            <flux:icon.arrow-down class="size-4 text-zinc-400 sm:hidden" />
                                            <flux:icon.arrow-right class="hidden size-4 text-zinc-400 sm:block" />
                                            <div class="min-w-0">
                                                <flux:text size="xs">Após aplicar</flux:text>
                                                <p class="mt-0.5 wrap-break-word text-sm font-medium text-zinc-900 dark:text-white">{{ $change['proposed'] }}</p>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </section>
                </div>
            </div>

            <div class="mt-5 flex shrink-0 flex-wrap justify-end gap-3">
                <flux:button type="button" variant="outline" wire:click="cancelCepLookup">{{ $cepLookupPreviewChanges === [] ? 'Fechar' : 'Cancelar' }}</flux:button>
                @if ($cepLookupPreviewChanges !== [])
                    <flux:button type="button" variant="primary" icon="check" wire:click="applyCepLookup" wire:loading.attr="disabled" wire:target="applyCepLookup">
                        Aplicar dados ao formulário
                    </flux:button>
                @endif
            </div>
        </div>
    </flux:modal>
