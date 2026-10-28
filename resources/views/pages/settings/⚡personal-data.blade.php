<?php

use App\Concerns\OwnPersonalDataValidationRules;
use App\Concerns\InteractsWithSupervisorQualifications;
use App\Concerns\InteractsWithAddressLookup;
use App\Helpers\BrazilianAddressHelper;
use App\Helpers\BrazilianContactHelper;
use App\Models\UserPersonalData;
use App\Support\ActiveAffiliationContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dados pessoais')] class extends Component
{
    use OwnPersonalDataValidationRules;
    use InteractsWithSupervisorQualifications;
    use InteractsWithAddressLookup;

    #[Locked]
    public int $ownerId;

    #[Locked]
    public int $affiliationId;

    public bool $hasAddress = false;

    public function boot(): void
    {
        Gate::authorize('viewOwn', UserPersonalData::class);
    }

    public function mount(ActiveAffiliationContext $context): void
    {
        $user = Auth::user();
        $affiliation = $context->currentFor($user, app('session.store'));
        $this->ownerId = $user->id;
        $this->affiliationId = $affiliation->id;
        $personalData = $user->personalData()->with('address')->first();
        foreach (array_diff($this->ownPersonalDataFields($affiliation->type), ['qualifications']) as $field) {
            $value = $personalData?->{$field};
            if (in_array($field, ['rg_issue_date', 'birth_date'], true)) {
                $value = $value?->toDateString();
            } elseif ($field === 'phone') {
                $value = $value === null ? '' : BrazilianContactHelper::formatPhone($value);
            } elseif ($value instanceof \BackedEnum) {
                $value = $value->value;
            }
            $oldValue = old($field, $value);
            $this->values[$field] = is_scalar($oldValue) ? (string) $oldValue : '';
        }
        if ($user->can('updateProfessionalData', UserPersonalData::class)) {
            $this->loadSupervisorQualifications($user);
        }
        if ($user->can('updateStudentData', UserPersonalData::class)) {
            $address = $personalData?->address;
            $this->hasAddress = session()->hasOldInput('address') ? is_array(old('address')) : $address !== null;
            $this->values['address'] = [];
            foreach (['city_id', 'street', 'number', 'neighborhood', 'zip_code'] as $field) {
                $value = $address?->{$field};
                if ($field === 'zip_code' && $address?->zip_code !== null) {
                    $value = BrazilianAddressHelper::formatCep($address->zip_code);
                }
                $oldValue = old('address.'.$field, is_scalar($value) ? (string) $value : '');
                $this->values['address'][$field] = is_scalar($oldValue) ? (string) $oldValue : '';
            }
            $this->selectedCityId = $this->values['address']['city_id'];
        }
    }

    protected function authorizeAddressLookup(): void
    {
        Gate::authorize('updateStudentData', UserPersonalData::class);
        $this->hydrate(app(ActiveAffiliationContext::class));
        abort_unless($this->hasAddress, 403);
    }

    public function hydrate(ActiveAffiliationContext $context): void
    {
        $user = Auth::user();
        abort_unless($user->id === $this->ownerId && $context->currentFor($user, app('session.store'))?->id === $this->affiliationId, 403);
    }
};
?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">Configurações de dados pessoais</flux:heading>

    <x-pages::settings.layout heading="Dados pessoais" subheading="Atualize os dados permitidos pelo vínculo selecionado" :wide="true">
        @if (session('status'))
            <div hidden x-data x-init="$nextTick(() => $flux.toast(@js(session('status')), { variant: 'success' }))"></div>
        @endif

        <form data-unsaved-changes="native-editor" data-unsaved-initial-dirty="{{ $errors->any() ? 'true' : 'false' }}" method="POST" action="{{ route('personal-data.update') }}" class="space-y-6" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false">
            @csrf
            @method('PUT')
            <input type="hidden" name="context_affiliation_id" value="{{ $affiliationId }}" />
            <flux:error name="context_affiliation_id" />
            <flux:text>Estes dados pertencem à sua conta e são compartilhados entre seus vínculos. Alterações não modificam os dados históricos dos estágios já formalizados.</flux:text>

            <section aria-labelledby="personal-contact-heading" class="space-y-4">
                <flux:heading id="personal-contact-heading" level="2" size="lg">Contato</flux:heading>
                <flux:input name="phone" wire:model="values.phone" :value="$values['phone']" error:name="phone" label="Telefone com DDD" type="tel" maxlength="20" autocomplete="tel" placeholder="(00) 00000-0000" />
                <flux:text size="sm">Contato compartilhado entre seus vínculos. O telefone será exigido para enviar uma solicitação de estágio.</flux:text>
            </section>

            @can('updateStudentData', \App\Models\UserPersonalData::class)
                <flux:separator />
                <section aria-labelledby="student-personal-heading" class="space-y-4">
                    <flux:heading id="student-personal-heading" level="2" size="lg">Identificação do discente</flux:heading>
                    <flux:text size="sm">Ao informar o RG, preencha também o órgão emissor e a data de emissão. Para limpar o RG, deixe os três campos vazios.</flux:text>
                    <div class="grid items-start gap-5 sm:grid-cols-2">
                        <flux:input name="rg" wire:model="values.rg" :value="$values['rg']" error:name="rg" label="RG" maxlength="255" />
                        <flux:input name="rg_issuer" wire:model="values.rg_issuer" :value="$values['rg_issuer']" error:name="rg_issuer" label="Órgão emissor" placeholder="SSP/RS" maxlength="255" />
                        <flux:input name="rg_issue_date" wire:model="values.rg_issue_date" :value="$values['rg_issue_date']" error:name="rg_issue_date" label="Data de emissão do RG" type="date" />
                        <flux:input name="birth_date" wire:model="values.birth_date" :value="$values['birth_date']" error:name="birth_date" label="Data de nascimento" type="date" autocomplete="bday" />
                    </div>
                </section>

                <flux:separator />
                <section aria-labelledby="student-address-heading" class="space-y-4">
                    <flux:heading id="student-address-heading" level="2" size="lg">Endereço atual</flux:heading>
                    <flux:checkbox wire:model.live="hasAddress" label="Informar endereço atual" />
                    <flux:text size="sm">Desmarque para remover o endereço atual da conta. Endereços históricos dos estágios serão preservados.</flux:text>
                    <flux:error name="address" />
                    @if ($hasAddress)
                        <x-address.form-fields :values="$values['address']" :selected-city-id="$selectedCityId" model="values.address" :selection-key="'personal-data-city-'.$citySelectionVersion" :personal-data="true" :city-message="$cityLookupMessage" :cep-lookup-changes="$this->cepLookupPreviewChanges()" />
                    @else
                        <input type="hidden" name="address" value="" />
                    @endif
                </section>
            @endcan

            @can('updateProfessionalData', \App\Models\UserPersonalData::class)
                <flux:separator />
                <section aria-labelledby="supervisor-professional-heading" class="space-y-4">
                    <flux:heading id="supervisor-professional-heading" level="2" size="lg">Dados profissionais do supervisor</flux:heading>
                    <x-supervisor.professional-fields :values="$values" :qualifications="$qualifications" />
                </section>
            @endcan

            <flux:button variant="primary" type="submit" :loading="false" x-bind:disabled="submitting" x-bind:aria-busy="submitting" wire:loading.attr="disabled">
                <flux:icon.loading x-show="submitting" x-cloak class="size-4" />
                Salvar dados pessoais
            </flux:button>
        </form>
    </x-pages::settings.layout>
</section>
