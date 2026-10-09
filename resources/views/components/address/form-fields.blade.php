@props([
    'values' => [],
    'model' => 'values.address',
    'selectedCityId' => null,
    'campusId' => null,
    'selectionKey' => 'address-city',
    'cityMessage' => '',
])

<flux:text class="mt-1" role="status">{{ $cityMessage !== '' ? $cityMessage : 'Selecione a UF e digite pelo menos 2 caracteres para buscar a cidade.' }}</flux:text>
<div class="mt-5 grid items-start gap-5 sm:grid-cols-2">
    <livewire:cities.select :selected-city-id="$selectedCityId" :campus-id="$campusId" :key="$selectionKey" :city-error="$errors->first('address.city_id')" />
    <flux:input name="address[street]" error:name="address.street" label="Logradouro" wire:model="{{ $model }}.street" :value="$values['street'] ?? ''" :invalid="$errors->has('address.street')" maxlength="255" autocomplete="address-line1" required />
    <flux:input name="address[number]" error:name="address.number" label="Número" wire:model="{{ $model }}.number" :value="$values['number'] ?? ''" :invalid="$errors->has('address.number')" maxlength="255" required />
    <flux:input name="address[neighborhood]" error:name="address.neighborhood" label="Bairro" wire:model="{{ $model }}.neighborhood" :value="$values['neighborhood'] ?? ''" :invalid="$errors->has('address.neighborhood')" maxlength="255" required />
    <flux:input name="address[zip_code]" error:name="address.zip_code" label="CEP (opcional)" wire:model="{{ $model }}.zip_code" :value="$values['zip_code'] ?? ''" :invalid="$errors->has('address.zip_code')" maxlength="9" autocomplete="postal-code" />
</div>
