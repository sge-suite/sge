<?php

namespace App\Concerns;

use App\Helpers\BrazilianAddressHelper;
use App\Helpers\DigitsHelper;
use App\Models\City;
use App\Support\BrasilApiCepLookup;
use Flux\Flux;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LaravelLegends\PtBrValidator\Rules\FormatoCep;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** @phpstan-require-extends Component */
trait InteractsWithAddressLookup
{
    /** @var array<string, mixed> */
    public array $values = [];

    public mixed $selectedCityId = null;

    #[Locked]
    public int $citySelectionVersion = 0;

    public string $cityLookupMessage = '';

    /** @var array<string, mixed> */
    #[Locked]
    public array $pendingCepLookup = [];

    public bool $showCepLookupPreview = false;

    abstract protected function authorizeAddressLookup(): void;

    public function lookupCep(BrasilApiCepLookup $lookup): void
    {
        $this->authorizeAddressLookup();
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
            $this->addCepLookupPreviewChange($changes, $label, $this->values['address'][$field] ?? '', $this->pendingCepLookup[$field]);
        }
        $selectedCityId = is_int($this->selectedCityId) || is_string($this->selectedCityId)
            ? filter_var($this->selectedCityId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $city = $selectedCityId === false ? null : City::find($selectedCityId);
        $this->addCepLookupPreviewChange($changes, 'Cidade', $city === null ? '' : $city->name.' ('.$city->state->value.')', $this->pendingCepLookup['city_display']);

        return $changes;
    }

    public function cancelCepLookup(): void
    {
        $this->pendingCepLookup = [];
        $this->showCepLookupPreview = false;
    }

    public function applyCepLookup(): void
    {
        $this->authorizeAddressLookup();
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

    /**
     * @param  list<array{label: string, current: string, proposed: string}>  $changes
     */
    private function addCepLookupPreviewChange(array &$changes, string $label, mixed $current, mixed $proposed): void
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
}
