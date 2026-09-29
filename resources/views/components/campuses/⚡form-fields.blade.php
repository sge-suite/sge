<?php

use App\Helpers\BrazilianAddressHelper;
use App\Helpers\BrazilianContactHelper;
use App\Helpers\DigitsHelper;
use App\Models\Campus;
use App\Models\City;
use App\Support\BrasilApiCompanyLookup;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LaravelLegends\PtBrValidator\Rules\Cnpj;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public ?int $campusId = null;

    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, mixed> */
    #[Locked]
    public array $pendingCompanyLookup = [];

    public mixed $selectedCityId = null;

    public string $selectedCompanyName = 'legal_name';

    public bool $showLookupPreview = false;

    #[Locked]
    public int $citySelectionVersion = 0;

    public string $cityLookupMessage = '';

    public function boot(): void
    {
        Gate::authorize('viewAdministration', Campus::class);
        Gate::authorize($this->campusId === null ? 'create' : 'update', $this->campusId === null ? Campus::class : Campus::findOrFail($this->campusId));
    }

    public function mount(?Campus $campus = null): void
    {
        $this->campusId = $campus?->id;
        if ($campus !== null) {
            Gate::authorize('update', $campus);
        }

        foreach (['name', 'cnpj', 'phone', 'legal_representative_name', 'legal_representative_position', 'insurance_company_name', 'insurance_policy_number'] as $field) {
            $this->values[$field] = old($field, $campus?->{$field}) ?? '';
        }
        foreach (['street', 'number', 'neighborhood', 'zip_code'] as $field) {
            $this->values['address'][$field] = old('address.'.$field, $campus?->address?->{$field}) ?? '';
        }
        $this->values['phone'] = filled($this->values['phone'])
            ? BrazilianContactHelper::formatPhone($this->values['phone'])
            : '';
        $this->values['address']['zip_code'] = filled($this->values['address']['zip_code'])
            ? BrazilianAddressHelper::formatCep($this->values['address']['zip_code'])
            : '';
        $this->selectedCityId = old('address.city_id', $campus?->address?->city_id);
    }

    public function lookupCnpj(BrasilApiCompanyLookup $lookup): void
    {
        $this->cityLookupMessage = '';
        $this->pendingCompanyLookup = [];
        $this->showLookupPreview = false;
        $this->selectedCompanyName = 'legal_name';
        $this->resetValidation(['cnpj', 'values.cnpj']);
        $this->validate(['values.cnpj' => ['bail', 'required', 'string', 'max:18', new Cnpj]], attributes: ['values.cnpj' => 'CNPJ']);

        try {
            $data = $lookup->lookup($this->values['cnpj']);
        } catch (ValidationException $exception) {
            $this->addError('values.cnpj', $exception->validator->errors()->first('cnpj'));

            return;
        }

        $city = preg_match('/^\d{7}$/', $data['address']['ibge_code']) === 1
            ? City::query()->forState($data['address']['state'])->where('ibge_code', $data['address']['ibge_code'])->first()
            : null;

        $state = $data['address']['state'];
        $cityDisplay = $city !== null
            ? $city->name.' ('.$city->state->value.')'
            : ($data['address']['city_name'] !== ''
                ? $data['address']['city_name'].' ('.$state.') — selecione no catálogo'
                : ($state !== '' ? 'Selecione uma cidade de '.$state : 'Selecione a UF e a cidade manualmente'));

        $this->pendingCompanyLookup = [
            'cnpj' => DigitsHelper::only($this->values['cnpj']),
            'legal_name' => $data['legal_name'],
            'trade_name' => $data['trade_name'],
            'phone' => $data['phone'] !== '' ? BrazilianContactHelper::formatPhone($data['phone']) : '',
            'address' => [
                'street' => $data['address']['street'],
                'number' => $data['address']['number'],
                'neighborhood' => $data['address']['neighborhood'],
                'zip_code' => $data['address']['zip_code'] !== '' ? BrazilianAddressHelper::formatCep($data['address']['zip_code']) : '',
                'city_id' => $city?->id,
                'city_display' => $cityDisplay,
            ],
        ];
        $this->showLookupPreview = true;
    }

    /**
     * @return list<array{label: string, current: string, proposed: string}>
     */
    public function lookupPreviewChanges(): array
    {
        if ($this->pendingCompanyLookup === []) {
            return [];
        }

        $changes = [];
        $name = $this->pendingCompanyLookup[$this->selectedCompanyName] ?? '';
        $address = is_array($this->values['address'] ?? null) ? $this->values['address'] : [];

        $this->addLookupPreviewChange($changes, 'Nome do campus', $this->values['name'] ?? '', $name);
        $this->addLookupPreviewChange($changes, 'Telefone', $this->values['phone'] ?? '', $this->pendingCompanyLookup['phone']);
        $this->addLookupPreviewChange($changes, 'Logradouro', $address['street'] ?? '', $this->pendingCompanyLookup['address']['street']);
        $this->addLookupPreviewChange($changes, 'Número', $address['number'] ?? '', $this->pendingCompanyLookup['address']['number']);
        $this->addLookupPreviewChange($changes, 'Bairro', $address['neighborhood'] ?? '', $this->pendingCompanyLookup['address']['neighborhood']);
        $this->addLookupPreviewChange($changes, 'CEP', $address['zip_code'] ?? '', $this->pendingCompanyLookup['address']['zip_code']);

        $selectedCityId = filter_var($this->selectedCityId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $currentCity = $selectedCityId === false ? null : City::find($selectedCityId);
        $currentCityName = $currentCity === null ? '' : $currentCity->name.' ('.$currentCity->state->value.')';
        $this->addLookupPreviewChange($changes, 'Cidade', $currentCityName, $this->pendingCompanyLookup['address']['city_display']);

        return $changes;
    }

    public function cancelCompanyLookup(): void
    {
        $this->pendingCompanyLookup = [];
        $this->showLookupPreview = false;
    }

    public function applyCompanyLookup(): void
    {
        $this->resetValidation(['values.cnpj', 'selectedCompanyName']);

        $currentCnpj = is_string($this->values['cnpj'] ?? null) ? DigitsHelper::only($this->values['cnpj']) : '';

        if ($this->pendingCompanyLookup === [] || ! hash_equals($this->pendingCompanyLookup['cnpj'], $currentCnpj)) {
            $this->cancelCompanyLookup();
            $this->addError('values.cnpj', 'O CNPJ mudou desde a consulta. Consulte novamente antes de aplicar os dados.');

            return;
        }

        if (! in_array($this->selectedCompanyName, ['legal_name', 'trade_name'], true)
            || ! filled($this->pendingCompanyLookup[$this->selectedCompanyName] ?? null)) {
            $this->addError('selectedCompanyName', 'Selecione um nome disponível para o campus.');

            return;
        }

        $this->values['name'] = $this->pendingCompanyLookup[$this->selectedCompanyName];

        if (! is_array($this->values['address'] ?? null)) {
            $this->values['address'] = [];
        }

        if ($this->pendingCompanyLookup['phone'] !== '') {
            $this->values['phone'] = $this->pendingCompanyLookup['phone'];
        }

        foreach (['street', 'number', 'neighborhood', 'zip_code'] as $field) {
            if ($this->pendingCompanyLookup['address'][$field] !== '') {
                $this->values['address'][$field] = $this->pendingCompanyLookup['address'][$field];
            }
        }

        $this->selectedCityId = $this->pendingCompanyLookup['address']['city_id'];
        $this->citySelectionVersion++;
        $this->cityLookupMessage = $this->pendingCompanyLookup['address']['city_id'] === null
            ? 'Cidade não encontrada no catálogo. Selecione a UF e busque a cidade.'
            : '';

        Flux::toast(variant: 'success', text: 'Dados aplicados ao formulário. Confira as informações antes de salvar.');

        $this->cancelCompanyLookup();
    }

    /**
     * @param list<array{label: string, current: string, proposed: string}> $changes
     */
    private function addLookupPreviewChange(array &$changes, string $label, mixed $current, mixed $proposed): void
    {
        $current = is_scalar($current) ? trim((string) $current) : '';
        $proposed = is_scalar($proposed) ? trim((string) $proposed) : '';

        if ($proposed === '' || $current === $proposed) {
            return;
        }

        $changes[] = [
            'label' => $label,
            'current' => $current === '' ? 'Sem valor preenchido' : $current,
            'proposed' => $proposed,
        ];
    }
}; ?>

<div>
    <div class="grid gap-8 xl:grid-cols-2 xl:items-start xl:gap-x-12">
        <section class="min-w-0 xl:col-start-1 xl:row-start-1" aria-labelledby="campus-registration-heading">
            <flux:heading id="campus-registration-heading" size="lg" level="2">Dados do campus</flux:heading>
            <flux:text class="mt-1">Informe a identificação e o telefone institucional.</flux:text>
            <div class="mt-5 grid items-start gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <flux:input name="name" error:name="name" label="Nome do campus" wire:model="values.name" :value="$values['name']" maxlength="255" required autocomplete="organization" />
                </div>
                <div class="sm:col-span-2 grid items-start gap-x-5 gap-y-4 sm:grid-cols-2">
                    <div>
                        <flux:input name="cnpj" error:name="cnpj" label="CNPJ" wire:model="values.cnpj" :value="$values['cnpj']" maxlength="18" placeholder="00.000.000/0000-00" :invalid="$errors->has('values.cnpj') || $errors->has('cnpj')" required />
                        <flux:error name="values.cnpj" />
                    </div>
                    <flux:input name="phone" error:name="phone" label="Telefone" wire:model="values.phone" :value="$values['phone']" maxlength="15" type="tel" autocomplete="tel" placeholder="(55) 3333-3333" required />
                    <div class="sm:col-span-2 flex flex-col items-start gap-2">
                        <flux:text size="sm">Busque na BrasilAPI para preencher o nome, o telefone e o endereço do campus. Confira os dados antes de salvar.</flux:text>
                        <flux:button type="button" size="sm" icon="magnifying-glass" wire:click="lookupCnpj" wire:loading.attr="disabled" wire:target="lookupCnpj">
                            Buscar dados na BrasilAPI
                        </flux:button>
                    </div>
                </div>
            </div>
        </section>

        <flux:separator class="xl:col-start-1 xl:row-start-2" />

        <section class="min-w-0 xl:col-start-2 xl:row-span-5 xl:row-start-1" aria-labelledby="campus-address-heading">
            <flux:heading id="campus-address-heading" size="lg" level="2">Endereço</flux:heading>
            <flux:text class="mt-1" role="status">{{ $cityLookupMessage !== '' ? $cityLookupMessage : 'Selecione a UF e digite pelo menos 2 caracteres para buscar a cidade.' }}</flux:text>
            <div class="mt-5 grid items-start gap-5 sm:grid-cols-2">
                <livewire:cities.select :selected-city-id="$selectedCityId" :key="'campus-city-'.$citySelectionVersion" :city-error="$errors->first('address.city_id')" />
                <flux:input name="address[street]" error:name="address.street" label="Logradouro" wire:model="values.address.street" :value="$values['address']['street']" :invalid="$errors->has('address.street')" maxlength="255" autocomplete="address-line1" required />
                <flux:input name="address[number]" error:name="address.number" label="Número" wire:model="values.address.number" :value="$values['address']['number']" :invalid="$errors->has('address.number')" maxlength="255" required />
                <flux:input name="address[neighborhood]" error:name="address.neighborhood" label="Bairro" wire:model="values.address.neighborhood" :value="$values['address']['neighborhood']" :invalid="$errors->has('address.neighborhood')" maxlength="255" required />
                <flux:input name="address[zip_code]" error:name="address.zip_code" label="CEP (opcional)" wire:model="values.address.zip_code" :value="$values['address']['zip_code']" :invalid="$errors->has('address.zip_code')" maxlength="9" autocomplete="postal-code" />
            </div>
        </section>

        <flux:separator class="xl:hidden" />

        <section class="min-w-0 xl:col-start-1 xl:row-start-3" aria-labelledby="campus-representative-heading">
            <flux:heading id="campus-representative-heading" size="lg" level="2">Representante legal</flux:heading>
            <div class="mt-5 grid items-start gap-5 sm:grid-cols-2">
                <flux:input name="legal_representative_name" error:name="legal_representative_name" label="Nome" wire:model="values.legal_representative_name" :value="$values['legal_representative_name']" maxlength="255" required />
                <flux:input name="legal_representative_position" error:name="legal_representative_position" label="Cargo" wire:model="values.legal_representative_position" :value="$values['legal_representative_position']" maxlength="255" required />
            </div>
        </section>

        <flux:separator class="xl:col-start-1 xl:row-start-4" />

        <section class="min-w-0 xl:col-start-1 xl:row-start-5" aria-labelledby="campus-insurance-heading">
            <flux:heading id="campus-insurance-heading" size="lg" level="2">Seguro</flux:heading>
            <div class="mt-5 grid items-start gap-5 sm:grid-cols-2">
                <flux:input name="insurance_company_name" error:name="insurance_company_name" label="Seguradora" wire:model="values.insurance_company_name" :value="$values['insurance_company_name']" maxlength="255" required />
                <flux:input name="insurance_policy_number" error:name="insurance_policy_number" label="Número da apólice" wire:model="values.insurance_policy_number" :value="$values['insurance_policy_number']" maxlength="255" required />
            </div>
        </section>
    </div>

    <flux:modal
        name="campus-cnpj-preview"
        wire:model.self="showLookupPreview"
        class="w-[calc(100vw_-_2rem)] max-h-[calc(100dvh_-_2rem)] max-w-5xl! overflow-hidden"
    >
        <div class="flex max-h-[calc(100dvh_-_5rem)] min-h-0 flex-col">
            <div class="shrink-0">
                <flux:heading size="lg" level="2">Revise os dados encontrados</flux:heading>
                <flux:text class="mt-2">Nada será alterado no formulário até você confirmar. Valores que não vieram da consulta serão mantidos.</flux:text>
            </div>

            @php($lookupPreviewChanges = $this->lookupPreviewChanges())

            <div class="scroll-fade-y mt-5 min-h-0 flex-1 overflow-y-auto overscroll-contain pr-2">
                <div class="space-y-5">
                    <flux:field>
                        <flux:label>Nome que será usado no campus</flux:label>
                        <flux:radio.group wire:model.live="selectedCompanyName" variant="cards" class="grid gap-3 sm:grid-cols-2">
                            <flux:radio value="legal_name" label="Razão social" description="Nome empresarial oficial." />
                            @if ($pendingCompanyLookup['trade_name'] ?? false)
                                <flux:radio value="trade_name" label="Nome fantasia" description="Nome de divulgação da empresa." />
                            @endif
                        </flux:radio.group>
                        <flux:error name="selectedCompanyName" />
                    </flux:field>

                    <section aria-labelledby="campus-cnpj-changes-heading">
                        <flux:heading id="campus-cnpj-changes-heading" level="3">Alterações previstas</flux:heading>
                        <flux:text size="sm" class="mt-1">Confira o valor atual e o valor que será aplicado.</flux:text>

                        @if ($lookupPreviewChanges === [])
                            <flux:callout class="mt-3" icon="information-circle">Os dados encontrados já correspondem ao formulário. Nenhum campo será alterado.</flux:callout>
                        @else
                            <div class="mt-3">
                                <div class="grid gap-3 pb-1 lg:grid-cols-2">
                                    @foreach ($lookupPreviewChanges as $change)
                                        <div class="grid gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] sm:items-center">
                                            <div class="min-w-0">
                                                <flux:text size="sm">{{ $change['label'] }}</flux:text>
                                                <flux:text size="xs" class="mt-1">Valor atual</flux:text>
                                                <p class="mt-0.5 break-words text-sm text-zinc-600 dark:text-zinc-300">{{ $change['current'] }}</p>
                                            </div>
                                            <flux:icon.arrow-down class="size-4 text-zinc-400 sm:hidden" />
                                            <flux:icon.arrow-right class="hidden size-4 text-zinc-400 sm:block" />
                                            <div class="min-w-0">
                                                <flux:text size="xs">Após aplicar</flux:text>
                                                <p class="mt-0.5 break-words text-sm font-medium text-zinc-900 dark:text-white">{{ $change['proposed'] }}</p>
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
                <flux:button type="button" variant="outline" wire:click="cancelCompanyLookup">Cancelar</flux:button>
                <flux:button type="button" variant="primary" icon="check" wire:click="applyCompanyLookup" wire:loading.attr="disabled" wire:target="applyCompanyLookup">
                    Aplicar dados ao formulário
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
