<?php

use App\Models\User;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Enums\AffiliationType;
use App\Helpers\DigitsHelper;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use LaravelLegends\PtBrValidator\Rules\Cpf;

new class extends Component
{
    #[Locked]
    public ?int $userId = null;

    #[Locked]
    public ?int $affiliationId = null;

    #[Locked]
    public string $consultedCpf = '';

    public string $cpf = '';
    public string $name = '';
    public string $email = '';
    public string $type = '';
    public string $campusId = '';
    public string $registrationNumber = '';
    public string $registrationSourceId = '';

    public function boot(): void
    {
        Gate::authorize('create', User::class);
    }

    public function mount(?User $user = null, ?Affiliation $affiliation = null): void
    {
        $this->userId = $user?->id;
        $this->affiliationId = $affiliation?->id;
        $value = static function (string $field, mixed $default = ''): string {
            $old = old($field, $default);

            return is_scalar($old) ? (string) $old : '';
        };
        $this->cpf = $value('cpf', $user?->cpf ?? '');
        $this->name = $value('name');
        $this->email = $value('email', $affiliation?->email ?? $user?->email ?? '');
        $this->type = $value('type', $affiliation?->type->value ?? '');
        $this->campusId = $value('campus_id', $affiliation?->campus_id ?? '');
        $this->registrationNumber = $value('registration_number', $affiliation?->registration_number ?? '');
        if ($user !== null || (old('consulted_cpf') !== null && old('consulted_cpf') === DigitsHelper::only($this->cpf))) {
            $this->consultedCpf = DigitsHelper::only($this->cpf);
        }
    }

    public function updatedCpf(): void
    {
        $this->consultedCpf = '';
        $this->name = '';
        $this->email = '';
        $this->registrationSourceId = '';
        $this->resetValidation();
        unset($this->account);
    }

    public function updatedType(): void
    {
        $this->campusId = '';
    }

    public function lookupCpf(): void
    {
        Gate::authorize('create', User::class);
        $this->cpf = DigitsHelper::only($this->cpf);
        $this->validate(['cpf' => ['required', 'string', new Cpf]]);
        $this->consultedCpf = $this->cpf;
        unset($this->account);
        $this->email = $this->account?->email ?? $this->email;
    }

    #[Computed]
    public function account(): ?User
    {
        if ($this->userId !== null) {
            return User::findOrFail($this->userId);
        }

        return $this->consultedCpf === '' ? null : User::where('cpf', $this->consultedCpf)->first();
    }

    #[Computed]
    public function affiliation(): ?Affiliation
    {
        if ($this->affiliationId === null) {
            return null;
        }
        $affiliation = $this->account->affiliations()->with('campus')->findOrFail($this->affiliationId);
        Gate::authorize('update', $affiliation);

        return $affiliation;
    }

    public function updatedRegistrationSourceId(): void
    {
        Gate::authorize('create', User::class);
        if ($this->registrationSourceId === '') {
            return;
        }
        $affiliation = $this->account?->affiliations()->whereNotNull('registration_number')->find($this->registrationSourceId);
        if ($affiliation === null) {
            $this->addError('registrationSourceId', 'Selecione um vínculo desta conta.');
            $this->registrationSourceId = '';

            return;
        }
        $this->registrationNumber = $affiliation->registration_number;
        $this->resetValidation('registrationSourceId');
    }

    /** @return array<int, string> */
    #[Computed]
    public function registrationOptions(): array
    {
        return $this->account?->affiliations()->with('campus')->whereNotNull('registration_number')->orderBy('id')->get()
            ->mapWithKeys(fn (Affiliation $affiliation): array => [$affiliation->id => "{$affiliation->registration_number} · {$affiliation->type->label()} · ".($affiliation->campus?->name ?? 'Todos os campi')])->all() ?? [];
    }

    /** @return array<int, string> */
    #[Computed]
    public function campusOptions(): array
    {
        return $this->campuses->pluck('name', 'id')->all();
    }

    #[Computed]
    public function campuses(): \Illuminate\Database\Eloquent\Collection
    {
        return Campus::query()->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name']);
    }
}; ?>

<div class="grid w-full items-start gap-8 lg:grid-cols-2">
    @php($account = $this->account)
    @php($affiliation = $this->affiliation)
    <section class="min-w-0 space-y-5">
        <flux:heading size="lg" level="2">Conta</flux:heading>
        @if ($userId === null)
            <flux:input name="cpf" label="CPF" wire:model.live="cpf" wire:keydown.enter.prevent="lookupCpf" :value="$cpf" maxlength="14" error:name="cpf" />
            <flux:button type="button" wire:click="lookupCpf" wire:loading.attr="disabled">Consultar CPF</flux:button>
            <input type="hidden" name="consulted_cpf" value="{{ $consultedCpf }}" />
            <flux:error name="consulted_cpf" />
        @endif
        @if ($account !== null)
            <dl class="space-y-3">
                <div><dt class="text-sm text-zinc-500">Nome completo</dt><dd>{{ $account->name }}</dd></div>
                <div><dt class="text-sm text-zinc-500">CPF</dt><dd>{{ $account->cpf }}</dd></div>
                <div><dt class="text-sm text-zinc-500">E-mail de login</dt><dd class="break-all">{{ $account->email }}</dd></div>
            </dl>
            @can('view', $account)
                <flux:button :href="route('users.show', $account)" wire:navigate>Consultar vínculos administrativos</flux:button>
            @endcan
            <flux:text size="sm">Este formulário altera somente o vínculo. Para corrigir nome, CPF ou e-mail de login, use a edição dos dados de acesso.</flux:text>
        @elseif ($consultedCpf !== '')
            <flux:text>Nenhuma conta encontrada. Cadastre a conta e seu primeiro vínculo administrativo.</flux:text>
            <flux:input name="name" label="Nome completo" wire:model="name" :value="$name" maxlength="255" error:name="name" />
        @else
            <flux:text>Informe um CPF válido e consulte para continuar.</flux:text>
        @endif
    </section>
    @if ($consultedCpf !== '')
        <section class="min-w-0 space-y-5">
            <flux:heading size="lg" level="2">Vínculo administrativo</flux:heading>
            <flux:input type="email" name="email" label="E-mail" wire:model="email" :value="$email" error:name="email" maxlength="255" />
            <flux:text size="sm">{{ $account === null ? 'Este endereço será usado para o login e o primeiro vínculo. O convite permitirá solicitar a definição de senha.' : 'Este endereço será usado somente no vínculo.' }}</flux:text>
            @if ($affiliation !== null)
                <flux:text>{{ $affiliation->type->label() }} · {{ $affiliation->campus?->name ?? 'Todos os campi' }}</flux:text>
                <flux:text size="sm">Para mudar a função ou o campus, cadastre outro vínculo e desative o anterior.</flux:text>
            @else
                <x-select name="type" label="Tipo de vínculo" wire:model.live="type" :value="$type" :options="['system_administrator' => 'Administrador do Sistema', 'campus_administrator' => 'Administrador do Campus']" :error="$errors->first('type')" />
                @if ($type === AffiliationType::CampusAdministrator->value)
                    <x-select name="campus_id" label="Campus ativo" wire:model="campusId" :value="$campusId" :options="$this->campusOptions" :error="$errors->first('campus_id')" searchable />
                @endif
            @endif
            @if ($affiliation === null && $account !== null && $this->registrationOptions !== [])
                <div class="space-y-2">
                    <x-select label="Copiar matrícula de outro vínculo" wire:model.live="registrationSourceId" :value="$registrationSourceId" :options="$this->registrationOptions" :error="$errors->first('registrationSourceId')" placeholder="Escolha um vínculo para copiar" />
                    <flux:text size="sm">A matrícula copiada pode ser ajustada antes de salvar.</flux:text>
                </div>
            @endif
            <flux:input name="registration_number" label="Matrícula / registro institucional" wire:model="registrationNumber" :value="$registrationNumber" maxlength="255" error:name="registration_number" />
        </section>
    @endif
</div>
