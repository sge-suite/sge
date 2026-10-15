<?php

use App\Helpers\BrazilianAddressHelper;
use App\Helpers\BrazilianContactHelper;
use App\Helpers\DigitsHelper;
use App\Helpers\BrazilianDocumentHelper;
use App\Models\City;
use App\Models\GrantingParty;
use App\Support\ActiveAffiliationContext;
use App\Support\BrasilApiCepLookup;
use App\Support\BrasilApiCompanyLookup;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LaravelLegends\PtBrValidator\Rules\Cnpj;
use LaravelLegends\PtBrValidator\Rules\Cpf;
use LaravelLegends\PtBrValidator\Rules\FormatoCep;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public ?int $grantingPartyId = null;

    #[Locked]
    public ?int $campusId = null;

    /** @var array<string, mixed> */
    public array $values = [];

    public mixed $selectedCityId = null;

    /** @var array<string, mixed> */
    #[Locked]
    public array $pendingCepLookup = [];

    #[Locked]
    public int $citySelectionVersion = 0;

    public bool $showCepLookupPreview = false;

    public string $cityLookupMessage = '';

    /** @var array<string, mixed> */
    #[Locked]
    public array $pendingCompanyLookup = [];

    public bool $showLookupPreview = false;

    public string $selectedCompanyName = 'legal_name';

    /** @return array{label: string, characters: int, maxlength: int, placeholder: string} */
    #[Computed]
    public function documentField(): array
    {
        return match ($this->values['document_type'] ?? '') {
            'cpf' => ['label' => 'CPF', 'characters' => 11, 'maxlength' => 14, 'placeholder' => '000.000.000-00'],
            'cnpj' => ['label' => 'CNPJ', 'characters' => 14, 'maxlength' => 18, 'placeholder' => 'ZG.MXB.TA3/0001-93'],
            default => ['label' => 'Documento', 'characters' => 0, 'maxlength' => 18, 'placeholder' => 'Selecione o tipo de documento'],
        };
    }

    #[Computed]
    public function canLookupCnpj(): bool
    {
        return ($this->values['document_type'] ?? '') === 'cnpj';
    }

    public function updatedValues(mixed $value, ?string $key): void
    {
        if ($key === 'document_type') {
            $this->values['document_number'] = '';
            $this->cancelCompanyLookup();
            $this->resetValidation(['document_number', 'values.document_number']);
            unset($this->documentField, $this->canLookupCnpj);
        }

        if ($key === 'document_number') {
            $type = $this->values['document_type'] ?? '';
            if (in_array($type, ['cpf', 'cnpj'], true)) {
                $this->validate(['values.document_number' => ['bail', 'required', 'string', $type === 'cpf' ? new Cpf : new Cnpj]], attributes: ['values.document_number' => strtoupper($type)]);
            }
        }
    }

    public function boot(): void
    {
        Gate::authorize($this->grantingPartyId === null ? 'create' : 'update', $this->grantingPartyId === null ? GrantingParty::class : GrantingParty::findOrFail($this->grantingPartyId));
        $context = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'));
        abort_if($this->campusId !== null && $this->campusId !== $context?->campus_id, 403);
    }

    public function mount(?GrantingParty $grantingParty = null): void
    {
        Gate::authorize($grantingParty === null ? 'create' : 'update', $grantingParty ?? GrantingParty::class);
        $this->grantingPartyId = $grantingParty?->id;
        $this->campusId = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'))->campus_id;
        foreach (['document_type', 'document_number', 'name', 'representative_name', 'representative_role', 'phone', 'email', 'field_of_activity', 'professional_council', 'council_registration_number', 'credentialing_process_number'] as $field) {
            $value = old($field, $grantingParty?->{$field});
            $this->values[$field] = $value instanceof BackedEnum ? $value->value : (is_scalar($value) ? $value : '');
        }
        foreach (['street', 'number', 'neighborhood', 'zip_code'] as $field) {
            $value = old('address.'.$field, $grantingParty?->address?->{$field});
            $this->values['address'][$field] = is_scalar($value) ? $value : '';
        }
        $this->selectedCityId = old('address.city_id', $grantingParty?->address?->city_id);
    }

    public function lookupCep(BrasilApiCepLookup $lookup): void
    {
        $this->cancelCepLookup();
        $this->resetValidation(['address.zip_code', 'values.address.zip_code']);
        $cep = BrazilianAddressHelper::cepForValidation($this->values['address']['zip_code'] ?? null);
        Validator::make(['values' => ['address' => ['zip_code' => $cep]]], ['values.address.zip_code' => ['bail', 'required', 'string', new FormatoCep]], attributes: ['values.address.zip_code' => 'CEP'])->validate();

        try {
            $data = $lookup->lookup($cep);
        } catch (ValidationException $exception) {
            $this->addError('values.address.zip_code', $exception->validator->errors()->first('zip_code'));

            return;
        }

        $normalize = static fn (string $name): string => Str::lower(Str::ascii(trim($name)));
        $cities = City::query()->forState($data['state'])->get(['id', 'name', 'state'])
            ->filter(fn (City $city): bool => $normalize($city->name) === $normalize($data['city_name']));
        $city = $cities->count() === 1 ? $cities->first() : null;
        $this->pendingCepLookup = [...$data, 'city_id' => $city?->id,
            'city_display' => $city === null ? $data['city_name'].' ('.$data['state'].') — selecione no catálogo' : $city->name.' ('.$city->state->value.')'];
        $this->showCepLookupPreview = true;
    }

    /** @return list<array{label: string, current: string, proposed: string}> */
    public function cepLookupPreviewChanges(): array
    {
        if ($this->pendingCepLookup === []) {
            return [];
        }

        $changes = [];
        foreach (['street' => 'Logradouro', 'neighborhood' => 'Bairro'] as $field => $label) {
            $this->addLookupPreviewChange($changes, $label, $this->values['address'][$field] ?? '', $this->pendingCepLookup[$field]);
        }
        $city = is_scalar($this->selectedCityId) ? City::find($this->selectedCityId) : null;
        $this->addLookupPreviewChange($changes, 'Cidade', $city === null ? '' : $city->name.' ('.$city->state->value.')', $this->pendingCepLookup['city_display']);

        return $changes;
    }

    public function cancelCepLookup(): void
    {
        $this->pendingCepLookup = [];
        $this->showCepLookupPreview = false;
    }

    public function applyCepLookup(): void
    {
        $cep = BrazilianAddressHelper::cepForValidation($this->values['address']['zip_code'] ?? null);
        $validCep = is_string($cep) && Validator::make(['cep' => $cep], ['cep' => ['required', new FormatoCep]])->passes();
        if ($this->pendingCepLookup === [] || ! $validCep || ! hash_equals($this->pendingCepLookup['zip_code'], DigitsHelper::only($cep))) {
            $this->cancelCepLookup();
            $this->addError('values.address.zip_code', 'O CEP mudou desde a consulta. Consulte novamente antes de aplicar os dados.');

            return;
        }

        foreach (['street', 'neighborhood'] as $field) {
            if ($this->pendingCepLookup[$field] !== '') {
                $this->values['address'][$field] = $this->pendingCepLookup[$field];
            }
        }
        $this->values['address']['zip_code'] = BrazilianAddressHelper::formatCep($this->pendingCepLookup['zip_code']);
        $this->selectedCityId = $this->pendingCepLookup['city_id'];
        $this->citySelectionVersion++;
        $this->cityLookupMessage = $this->selectedCityId === null ? 'Cidade não encontrada de forma única no catálogo. Selecione a UF e busque a cidade.' : '';
        $this->resetValidation(['address.zip_code', 'values.address.zip_code']);
        $this->cancelCepLookup();
        Flux::toast(variant: 'success', text: 'Endereço aplicado ao formulário. Confira as informações antes de salvar.');
    }

    public function lookupCnpj(BrasilApiCompanyLookup $lookup): void
    {
        abort_unless($this->canLookupCnpj, 403);
        $this->cityLookupMessage = '';
        $this->pendingCompanyLookup = [];
        $this->showLookupPreview = false;
        $this->selectedCompanyName = 'legal_name';
        $this->resetValidation(['document_number', 'values.document_number']);
        $this->validate(['values.document_number' => ['bail', 'required', 'string', 'max:18', new Cnpj]], attributes: ['values.document_number' => 'CNPJ']);

        try {
            $data = $lookup->lookup($this->values['document_number']);
        } catch (ValidationException $exception) {
            $this->addError('values.document_number', $exception->validator->errors()->first('cnpj'));

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
            'cnpj' => BrazilianDocumentHelper::normalizeCnpj($this->values['document_number']),
            'legal_name' => $data['legal_name'],
            'trade_name' => $data['trade_name'],
            'email' => $data['email'],
            'field_of_activity' => $data['field_of_activity'],
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

        $this->addLookupPreviewChange($changes, 'Nome da unidade', $this->values['name'] ?? '', $name);
        $this->addLookupPreviewChange($changes, 'E-mail', $this->values['email'] ?? '', $this->pendingCompanyLookup['email']);
        $this->addLookupPreviewChange($changes, 'Área de atuação', $this->values['field_of_activity'] ?? '', $this->pendingCompanyLookup['field_of_activity']);
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
        abort_unless($this->canLookupCnpj, 403);
        $this->resetValidation(['values.document_number', 'selectedCompanyName']);

        $currentCnpj = is_string($this->values['document_number'] ?? null) ? BrazilianDocumentHelper::normalizeCnpj($this->values['document_number']) : '';

        if ($this->pendingCompanyLookup === [] || ! hash_equals($this->pendingCompanyLookup['cnpj'], $currentCnpj)) {
            $this->cancelCompanyLookup();
            $this->addError('values.document_number', 'O CNPJ mudou desde a consulta. Consulte novamente antes de aplicar os dados.');

            return;
        }

        if (! in_array($this->selectedCompanyName, ['legal_name', 'trade_name'], true)
            || ! filled($this->pendingCompanyLookup[$this->selectedCompanyName] ?? null)) {
            $this->addError('selectedCompanyName', 'Selecione um nome disponível para a unidade.');

            return;
        }

        $this->cancelCepLookup();
        $this->values['name'] = $this->pendingCompanyLookup[$this->selectedCompanyName];

        if (! is_array($this->values['address'] ?? null)) {
            $this->values['address'] = [];
        }

        if ($this->pendingCompanyLookup['phone'] !== '') {
            $this->values['phone'] = $this->pendingCompanyLookup['phone'];
        }

        foreach (['email', 'field_of_activity'] as $field) {
            if ($this->pendingCompanyLookup[$field] !== '') {
                $this->values[$field] = $this->pendingCompanyLookup[$field];
            }
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

    /** @param list<array{label: string, current: string, proposed: string}> $changes */
    private function addLookupPreviewChange(array &$changes, string $label, mixed $current, mixed $proposed): void
    {
        $current = is_scalar($current) ? trim((string) $current) : '';
        $proposed = is_scalar($proposed) ? trim((string) $proposed) : '';

        $currentDigits = $label === 'CNPJ' ? BrazilianDocumentHelper::normalizeCnpj($current) : DigitsHelper::only($current);
        $proposedDigits = $label === 'CNPJ' ? BrazilianDocumentHelper::normalizeCnpj($proposed) : DigitsHelper::only($proposed);
        $sameValue = in_array($label, ['CPF', 'CNPJ', 'CPF/CNPJ', 'Telefone', 'CEP'], true)
            ? ($currentDigits !== '' && $currentDigits === $proposedDigits) || $current === $proposed
            : $current === $proposed;

        if ($proposed === '' || $sameValue) {
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
        <div class="min-w-0 space-y-8">
            <section aria-labelledby="granting-party-identification-heading">
                <flux:heading id="granting-party-identification-heading" size="lg" level="2">Identificação e atuação</flux:heading>
                <div class="mt-5 grid items-start gap-5 sm:grid-cols-2">
                    <div class="min-w-0">
                        <x-select name="document_type" wire:model.live="values.document_type" label="Tipo de documento" :value="$values['document_type']" :options="['' => 'Selecione'] + \App\Enums\PartyDocumentType::options()" required :error="$errors->first('document_type')" />
                    </div>
                    <div wire:key="document-input-{{ $values['document_type'] }}" class="min-w-0">
                        <flux:input name="document_number" error:name="document_number" :error:message="$errors->first('document_number') ?: $errors->first('values.document_number')" wire:model.blur="values.document_number" :value="$values['document_number']" :label="$this->documentField['label']" required :maxlength="$this->documentField['maxlength']" :placeholder="$this->documentField['placeholder']" :inputmode="($values['document_type'] ?? '') === 'cpf' ? 'numeric' : 'text'" autocapitalize="characters" :data-document-type="$values['document_type'] ?? ''" :disabled="$this->documentField['characters'] === 0" x-on:input="$el.value = $el.value.toUpperCase().replace(/[^A-Z0-9.\/-]/g, ''); if ($el.dataset.documentType === 'cpf') $el.value = $el.value.replace(/[A-Z]/g, ''); if ($el.value.replace(/[^A-Z0-9]/g, '').length > {{ $this->documentField['characters'] }}) $el.value = $el.value.replace(/[^A-Z0-9]/g, '').slice(0, {{ $this->documentField['characters'] }}); $wire.$set('values.document_number', $el.value, false)" :invalid="$errors->has('document_number') || $errors->has('values.document_number')" />
                    </div>
                    @if ($this->canLookupCnpj)
                        <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                            <flux:button type="button" size="sm" icon="magnifying-glass" wire:click="lookupCnpj" wire:loading.attr="disabled" wire:target="lookupCnpj">Consultar CNPJ</flux:button>
                            <flux:text size="sm">Revise os dados encontrados antes de aplicar ao formulário.</flux:text>
                            <flux:text wire:loading wire:target="lookupCnpj" role="status">Consultando CNPJ…</flux:text>
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <flux:input name="name" error:name="name" wire:model="values.name" :value="$values['name']" :label="($values['document_type'] ?? '') === 'cpf' ? 'Nome completo' : 'Razão social / nome da unidade'" required maxlength="255" />
                    </div>
                    <div class="sm:col-span-2">
                        <flux:input name="field_of_activity" error:name="field_of_activity" wire:model="values.field_of_activity" :value="$values['field_of_activity']" label="Área de atuação" maxlength="255" required />
                    </div>
                </div>
                <flux:text class="mt-3">Identifique a unidade pelo nome e endereço. Unidades distintas podem usar o mesmo CPF/CNPJ.</flux:text>
            </section>
            <flux:separator />
            <section aria-labelledby="granting-party-contact-heading">
                <flux:heading id="granting-party-contact-heading" size="lg" level="2">Representante e contatos</flux:heading>
                <div class="mt-5 grid items-start gap-5 sm:grid-cols-2">
                    <flux:input name="representative_name" error:name="representative_name" wire:model="values.representative_name" :value="$values['representative_name']" label="Nome do representante" maxlength="255" required />
                    <flux:input name="representative_role" error:name="representative_role" wire:model="values.representative_role" :value="$values['representative_role']" label="Cargo do representante" maxlength="255" required />
                    <flux:input name="phone" error:name="phone" wire:model="values.phone" :value="$values['phone']" label="Telefone com DDD" maxlength="20" />
                    <flux:input name="email" error:name="email" wire:model="values.email" :value="$values['email']" label="E-mail" maxlength="254" type="email" />
                </div>
            </section>
            <flux:separator />
            <section aria-labelledby="granting-party-credentialing-heading">
                <flux:heading id="granting-party-credentialing-heading" size="lg" level="2">Credenciamento</flux:heading>
                <flux:text class="mt-1">Dados opcionais. Preencha quando aplicável.</flux:text>
                <div class="mt-5 grid items-start gap-5 sm:grid-cols-2">
                    <flux:input name="professional_council" error:name="professional_council" wire:model="values.professional_council" :value="$values['professional_council']" label="Conselho profissional" maxlength="120" />
                    <flux:input name="council_registration_number" error:name="council_registration_number" wire:model="values.council_registration_number" :value="$values['council_registration_number']" label="Registro no conselho" maxlength="64" />
                    <div class="sm:col-span-2">
                        <flux:input name="credentialing_process_number" error:name="credentialing_process_number" wire:model="values.credentialing_process_number" :value="$values['credentialing_process_number']" label="Processo de credenciamento" maxlength="100" />
                    </div>
                </div>
            </section>
        </div>
        <section class="min-w-0" aria-labelledby="granting-party-address-heading">
            <flux:heading id="granting-party-address-heading" size="lg" level="2">Endereço</flux:heading>
            <x-address.form-fields :values="$values['address']" :selected-city-id="$selectedCityId" :campus-id="$campusId" :selection-key="'granting-party-city-'.$citySelectionVersion" :city-message="$cityLookupMessage" city-hint-placement="selection" :cep-lookup-changes="$this->cepLookupPreviewChanges()" />
        </section>
    </div>

    @if ($this->canLookupCnpj)
    <flux:modal
        name="granting-party-cnpj-preview"
        wire:model.self="showLookupPreview"
        class="w-[calc(100vw-2rem)] max-h-[calc(100dvh-2rem)] max-w-5xl! overflow-hidden"
    >
        <div class="flex max-h-[calc(100dvh-5rem)] min-h-0 flex-col">
            <div class="shrink-0">
                <flux:heading size="lg" level="2">Revise os dados encontrados</flux:heading>
                <flux:text class="mt-2">Nada será alterado no formulário até você confirmar. Valores que não vieram da consulta serão mantidos.</flux:text>
            </div>

            @php($lookupPreviewChanges = $this->lookupPreviewChanges())

            <div class="scroll-fade-y mt-5 min-h-0 flex-1 overflow-y-auto overscroll-contain pr-2">
                <div class="space-y-5">
                    <flux:field>
                        <flux:label>Nome que será usado na unidade</flux:label>
                        <flux:radio.group wire:model.live="selectedCompanyName" variant="cards" class="grid gap-3 sm:grid-cols-2">
                            <flux:radio value="legal_name" label="Razão social" description="Nome empresarial oficial." />
                            @if ($pendingCompanyLookup['trade_name'] ?? false)
                                <flux:radio value="trade_name" label="Nome fantasia" description="Nome de divulgação da empresa." />
                            @endif
                        </flux:radio.group>
                        <flux:error name="selectedCompanyName" />
                    </flux:field>

                    <section aria-labelledby="granting-party-cnpj-changes-heading">
                        <flux:heading id="granting-party-cnpj-changes-heading" level="3">Alterações previstas</flux:heading>
                        <flux:text size="sm" class="mt-1">Confira o valor atual e o valor que será aplicado.</flux:text>

                        @if ($lookupPreviewChanges === [])
                            <flux:callout class="mt-3" icon="information-circle">Os dados encontrados já correspondem ao formulário. Nenhum campo será alterado.</flux:callout>
                        @else
                            <div class="mt-3">
                                <div class="grid gap-3 pb-1 lg:grid-cols-2">
                                    @foreach ($lookupPreviewChanges as $change)
                                        <div wire:key="company-change-{{ $loop->index }}" class="grid gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] sm:items-center">
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
                <flux:button type="button" variant="outline" wire:click="cancelCompanyLookup">Cancelar</flux:button>
                <flux:button type="button" variant="primary" icon="check" wire:click="applyCompanyLookup" wire:loading.attr="disabled" wire:target="applyCompanyLookup">
                    Aplicar dados ao formulário
                </flux:button>
            </div>
        </div>
    </flux:modal>
    @endif
</div>
