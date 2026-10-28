<?php

use App\Models\User;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Support\ActiveAffiliationContext;
use App\Enums\AffiliationType;
use App\Helpers\DigitsHelper;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use LaravelLegends\PtBrValidator\Rules\Cpf;

new class extends Component
{
    use \App\Concerns\SearchesSelectOptions;

    protected function selectSearchSource(string $source): array
    {
        if ($source === 'campuses') {
            Gate::authorize('viewAdministration', User::class);
            abort_unless(! $this->campusRegistration && $this->affiliation === null && $this->type === AffiliationType::InternshipOffice->value, 403);
        } elseif ($source === 'courses') {
            abort_unless($this->campusRegistration && $this->affiliation === null && $this->type === AffiliationType::Student->value, 403);
        }

        return match ($source) {
            'campuses' => ['model' => Campus::class, 'options' => $this->campusOptions],
            'courses' => ['model' => Course::class, 'options' => $this->courseOptions],
            default => abort(404),
        };
    }

    use \App\Concerns\InteractsWithSupervisorQualifications;
    #[Locked]
    public ?int $activeAffiliationId = null;

    protected Affiliation $activeAffiliation;

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
    public string $courseId = '';
    public string $jobRole = '';
    public string $phone = '';

    public function boot(): void
    {
        $current = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'));
        Gate::authorize($current?->type === AffiliationType::InternshipOffice ? 'createOnCampus' : 'create', User::class);
        abort_unless($this->activeAffiliationId === null || $this->activeAffiliationId === $current->id, 403);
        $this->activeAffiliation = $current;
    }

    public function mount(?User $user = null, ?Affiliation $affiliation = null): void
    {
        $this->activeAffiliationId = $this->activeAffiliation->id;
        if ($affiliation !== null) {
            abort_unless($affiliation->user_id === $user?->id, 404);
            Gate::authorize('update', $affiliation);
        } elseif ($user !== null) {
            Gate::authorize('create', [Affiliation::class, $user]);
        }
        $this->userId = $user?->id;
        $this->affiliationId = $affiliation?->id;
        $value = static function (string $field, mixed $default = ''): string {
            $old = old($field, $default);

            return is_scalar($old) ? (string) $old : '';
        };
        $this->cpf = $value('cpf', $user?->cpf ?? '');
        $this->name = $value('name');
        $this->email = $value('email', $affiliation?->email ?? $user?->email ?? '');
        $this->type = $affiliation?->type->value ?? $value('type');
        if ($this->campusRegistration && ! array_key_exists($this->type, $this->typeOptions)) {
            $this->type = '';
        }
        $this->campusId = $value('campus_id', $affiliation?->campus_id ?? '');
        $this->registrationNumber = $value('registration_number', $affiliation?->registration_number ?? '');
        $this->courseId = $value('course_id');
        $professionalData = $affiliation?->type === AffiliationType::Supervisor ? $user?->personalData : null;
        $this->jobRole = $value('job_role', $professionalData?->job_role ?? '');
        $this->phone = $value('phone', filled($professionalData?->phone) ? \App\Helpers\BrazilianContactHelper::formatPhone($professionalData->phone) : '');
        $this->loadSupervisorQualifications($user);
        if ($user !== null || (old('consulted_cpf') !== null && old('consulted_cpf') === DigitsHelper::only($this->cpf))) {
            $this->consultedCpf = DigitsHelper::only($this->cpf);
        }
        if ($user === null && $affiliation === null && request()->has('internship_request')) {
            $this->prefillFromInternshipRequest();
        }
    }

    private function prefillFromInternshipRequest(): void
    {
        Gate::authorize('createOnCampus', User::class);
        $data = request()->validate(['internship_request' => ['required', 'integer', 'min:1']]);
        $request = \App\Models\InternshipRequest::query()->with('supervisorRegistrationRequest')->findOrFail($data['internship_request']);
        Gate::authorize('review', $request);
        abort_unless(in_array($request->status->value, ['submitted', 'under_review', 'pending_correction'], true), 409);
        $proposal = $request->supervisorRegistrationRequest;
        abort_unless($proposal !== null && in_array($proposal->status->value, ['submitted', 'under_review'], true), 409);
        if (session()->hasOldInput()) {
            return;
        }

        $this->cpf = $proposal->cpf;
        $this->lookupCpf();
        $this->type = AffiliationType::Supervisor->value;
        $this->name = $this->account?->name ?? $proposal->name;
        $this->email = $proposal->email;
        $this->phone = \App\Helpers\BrazilianContactHelper::formatPhone($proposal->phone);
        $this->jobRole = $proposal->job_role;
        $qualification = [
            'type' => $proposal->has_academic_background ? 'academic' : 'professional',
            'training_course' => $proposal->training_course ?? '',
            'education_level' => $proposal->education_level?->value ?? '',
            'experience_area' => $proposal->has_academic_background ? '' : $proposal->job_role,
            'experience_time' => $proposal->experience_time ?? '',
        ];
        $alreadyStored = collect($this->qualifications)->contains(fn (array $row): bool => collect($qualification)
            ->every(fn (mixed $value, string $field): bool => ($row[$field] ?? '') === $value));
        if (! $alreadyStored && count($this->qualifications) < 25) {
            $this->addSupervisorQualification($qualification['type']);
            $this->qualifications[array_key_last($this->qualifications)] = ['id' => null, ...$qualification];
        }
    }

    public function updatedCpf(): void
    {
        $this->consultedCpf = '';
        $this->name = '';
        $this->email = '';
        $this->registrationNumber = '';
        $this->jobRole = '';
        $this->phone = '';
        $this->qualifications = [];
        $this->resetValidation();
        unset($this->account);
    }

    public function updatedType(): void
    {
        $this->campusId = '';
        $this->courseId = '';
        if ($this->type === AffiliationType::Supervisor->value) {
            $this->loadSupervisorQualifications($this->account);
            $this->jobRole = $this->account?->personalData?->job_role ?? '';
            $this->phone = filled($this->account?->personalData?->phone) ? \App\Helpers\BrazilianContactHelper::formatPhone($this->account->personalData->phone) : '';
            $this->registrationNumber = '';
        } else {
            $this->jobRole = '';
            $this->phone = '';
            $this->qualifications = [];
        }
    }

    public function lookupCpf(): void
    {
        Gate::authorize($this->campusRegistration ? 'createOnCampus' : 'create', User::class);
        $this->cpf = DigitsHelper::only($this->cpf);
        $this->validate(['cpf' => ['required', 'string', new Cpf]]);
        $this->consultedCpf = $this->cpf;
        unset($this->account);
        $this->email = $this->account?->email ?? $this->email;
        $this->jobRole = $this->account?->personalData?->job_role ?? '';
        $this->phone = filled($this->account?->personalData?->phone) ? \App\Helpers\BrazilianContactHelper::formatPhone($this->account->personalData->phone) : '';
        $this->loadSupervisorQualifications($this->account);
    }

    #[Computed]
    public function account(): ?User
    {
        if ($this->userId !== null) {
            $user = User::findOrFail($this->userId);
            if ($this->affiliationId !== null) {
                Gate::authorize('update', $user->affiliations()->findOrFail($this->affiliationId));
            } else {
                Gate::authorize('create', [Affiliation::class, $user]);
            }

            return $user;
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

    /** @return array<int|string, string> */
    #[Computed]
    public function registrationOptions(): array
    {
        return $this->account?->affiliations()->whereNotNull('registration_number')
            ->when($this->campusRegistration, fn ($query) => $query->where('campus_id', $this->activeAffiliation->campus_id))
            ->orderBy('registration_number')->pluck('registration_number')
            ->map(fn (string $registrationNumber): string => trim($registrationNumber))
            ->filter(fn (string $registrationNumber): bool => $registrationNumber !== '')
            ->unique()->values()
            ->mapWithKeys(fn (string $registrationNumber): array => [$registrationNumber => $registrationNumber])
            ->all() ?? [];
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

    #[Computed]
    public function campusRegistration(): bool
    {
        return $this->activeAffiliation->type === AffiliationType::InternshipOffice;
    }

    #[Computed]
    public function campusName(): string
    {
        return $this->activeAffiliation->campus?->name ?? '';
    }

    /** @return array<string, string> */
    #[Computed]
    public function typeOptions(): array
    {
        return $this->campusRegistration
            ? array_diff_key(AffiliationType::options(), [AffiliationType::SystemAdministrator->value => true])
            : [AffiliationType::SystemAdministrator->value => AffiliationType::SystemAdministrator->label(), AffiliationType::InternshipOffice->value => AffiliationType::InternshipOffice->label()];
    }

    /** @return array<int, string> */
    #[Computed]
    public function courseOptions(): array
    {
        return Course::query()->where('campus_id', $this->activeAffiliation->campus_id)->whereNull('deactivated_at')->orderBy('name')->pluck('name', 'id')->all();
    }
}; ?>

<div class="grid w-full items-start gap-8 lg:grid-cols-2">
    @php($account = $this->account)
    @php($affiliation = $this->affiliation)
    <section class="min-w-0 space-y-5">
        <flux:heading size="lg" level="2">Conta</flux:heading>
        @if ($userId === null)
            <flux:input name="cpf" required label="CPF" placeholder="000.000.000-00" wire:model.live="cpf" wire:keydown.enter.prevent="lookupCpf" :value="$cpf" maxlength="14" error:name="cpf" />
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
            @can('viewAdministration', \App\Models\User::class)
                @can('view', $account)
                <flux:button :href="route('users.show', $account)" wire:navigate>Consultar vínculos administrativos</flux:button>
                @endcan
            @endcan
            <flux:text size="sm">{{ $this->campusRegistration && $affiliation === null ? 'A conta existente será preservada. Este cadastro adiciona um vínculo ao seu campus.' : 'Este formulário altera somente o vínculo. Para corrigir nome, CPF ou e-mail de login, use a edição dos dados de acesso.' }}</flux:text>
        @elseif ($consultedCpf !== '')
            <flux:text>{{ $this->campusRegistration ? 'Nenhuma conta encontrada. Cadastre a conta e seu primeiro vínculo.' : 'Nenhuma conta encontrada. Cadastre a conta e seu primeiro vínculo administrativo.' }}</flux:text>
            <flux:input name="name" required label="Nome completo" wire:model="name" :value="$name" maxlength="255" error:name="name" />
        @else
            <flux:text>Informe um CPF válido e consulte para continuar.</flux:text>
        @endif
    </section>
    @if ($consultedCpf !== '')
        <section class="min-w-0 space-y-5">
            <flux:heading size="lg" level="2">{{ $this->campusRegistration ? 'Vínculo institucional' : 'Vínculo administrativo' }}</flux:heading>
            <flux:input type="email" name="email" required label="E-mail" wire:model="email" :value="$email" error:name="email" maxlength="255" />
            <flux:text size="sm">{{ $account === null ? 'Este endereço será usado para o login e o primeiro vínculo. O convite permitirá solicitar a definição de senha.' : 'Este endereço será usado somente no vínculo.' }}</flux:text>
            @if ($affiliation !== null)
                <flux:text>{{ $affiliation->type->label() }} · {{ $affiliation->campus?->name ?? 'Todos os campi' }}</flux:text>
                <flux:text size="sm">Para mudar a função, o campus ou o curso, cadastre outro vínculo. A desativação deve ser feita por outra pessoa autorizada quando o vínculo pertencer à sua conta.</flux:text>
            @else
                <x-select name="type" required label="Tipo de vínculo" wire:model.live="type" :value="$type" :options="$this->typeOptions" :error="$errors->first('type')" />
                @if ($this->campusRegistration)
                    <flux:text>Campus: {{ $this->campusName }}</flux:text>
                    <flux:error name="campus_id" />
                    @if ($type === AffiliationType::Student->value)
                        <x-select search-source="courses" name="course_id" required label="Curso" wire:model="courseId" :value="$courseId" :options="$this->courseOptions" :error="$errors->first('course_id')" searchable />
                    @endif
                @elseif ($type === AffiliationType::InternshipOffice->value)
                    <x-select search-source="campuses" name="campus_id" required label="Campus ativo" wire:model="campusId" :value="$campusId" :options="$this->campusOptions" :error="$errors->first('campus_id')" searchable />
                @endif
            @endif
            @if ($type !== AffiliationType::Supervisor->value)
                @if ($affiliation === null && $account !== null)
                    <x-select-create name="registration_number" required label="Matrícula" wire:model="registrationNumber" :value="$registrationNumber" :options="$this->registrationOptions" :error="$errors->first('registration_number')" :max-length="255" placeholder="Escolha uma matrícula existente ou digite uma nova" />
                    <flux:text size="sm">Escolha uma matrícula de outro vínculo desta conta ou use uma nova. A gravação ocorre ao salvar o vínculo.</flux:text>
                @else
                    <flux:input name="registration_number" required label="Matrícula" wire:model="registrationNumber" :value="$registrationNumber" maxlength="255" error:name="registration_number" />
                @endif
            @elseif ($this->campusRegistration)
                <flux:text size="sm">Supervisor não possui matrícula. Os dados profissionais são opcionais e poderão ser preenchidos ou confirmados posteriormente.</flux:text>
                <flux:input name="phone" wire:model="phone" :value="$phone" label="Telefone com DDD" placeholder="(00) 00000-0000" maxlength="20" error:name="phone" />
                <x-supervisor.professional-fields
                    :values="['job_role' => $jobRole]" :models="['job_role' => 'jobRole']" :qualifications="$qualifications"
                />
            @endif
        </section>
    @endif
</div>
