<?php

use App\Actions\ManageHolidayRegistrationRequest;
use App\Enums\BrazilianState;
use App\Enums\RegistrationRequestStatus;
use App\Models\City;
use App\Models\Holiday;
use App\Models\HolidayRegistrationRequest;
use App\Support\ActiveAffiliationContext;
use App\Support\HolidayAccess;
use App\Support\PageSize;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Solicitações de feriado')] class extends Component
{
    use \App\Concerns\HandlesSearchFailures;
    use WithPagination;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: 'all')]
    public string $kind = 'all';

    #[Locked]
    public ?int $affiliationId = null;

    public bool $showRequestModal = false;

    public string $requestKind = 'holiday';

    public string $requestName = '';

    public string $requestState = '';

    public string $requestCityId = '';

    public string $requestCitySearch = '';

    public string $requestDate = '';

    public string $requestMonth = '';

    public string $requestDay = '';

    public string $requestNotes = '';

    #[Locked]
    public ?int $rejectTargetId = null;

    public bool $showRejectModal = false;

    public string $rejectReason = '';

    protected function searchRetryProperties(): array
    {
        return ['requestCities' => ['requestCities']];
    }

    public function boot(): void
    {
        Gate::authorize('viewAny', Holiday::class);
    }

    public function mount(): void
    {
        $this->affiliationId = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'))?->id;
        if ($this->status === '') {
            $this->status = $this->isAdmin ? RegistrationRequestStatus::Submitted->value : 'all';
        }
    }

    #[Computed]
    public function isAdmin(): bool
    {
        return app(HolidayAccess::class)->canManage(auth()->user());
    }

    public function updated(string $property): void
    {
        if ($property === 'requestState') {
            $this->requestCityId = '';
            $this->requestCitySearch = '';

            return;
        }
        if ($property === 'requestCitySearch') {
            $this->requestCitySearch = mb_substr(trim($this->requestCitySearch), 0, 255);

            return;
        }
        if ($property === 'requestCityId') {
            $this->requestCitySearch = '';

            return;
        }
        if (in_array($property, ['status', 'kind'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->status = $this->isAdmin ? RegistrationRequestStatus::Submitted->value : 'all';
        $this->kind = 'all';
        $this->resetPage();
    }

    #[Computed]
    public function hasFilters(): bool
    {
        $defaultStatus = $this->isAdmin ? RegistrationRequestStatus::Submitted->value : 'all';

        return $this->status !== $defaultStatus || $this->kind !== 'all';
    }

    public function openRequestModal(): void
    {
        Gate::authorize('requestRegistration', Holiday::class);
        $affiliation = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'));
        $campusCity = $affiliation?->campus?->address?->city;

        $this->reset('requestName', 'requestDate', 'requestMonth', 'requestDay', 'requestNotes', 'requestCitySearch');
        $this->requestKind = 'holiday';
        $this->requestState = $campusCity?->state->value ?? '';
        $this->requestCityId = $campusCity !== null ? (string) $campusCity->id : '';
        $this->resetErrorBag();
        $this->showRequestModal = true;
    }

    public function submitHolidayRequest(ManageHolidayRegistrationRequest $manage): void
    {
        Gate::authorize('requestRegistration', Holiday::class);
        $manage->submit(auth()->user(), (int) $this->affiliationId, [
            'kind' => $this->requestKind,
            'name' => $this->requestName,
            'city_id' => ctype_digit($this->requestCityId) ? (int) $this->requestCityId : null,
            'date' => $this->requestKind === 'holiday' ? $this->requestDate : null,
            'month' => $this->requestKind === 'recurrence' && ctype_digit($this->requestMonth) ? (int) $this->requestMonth : null,
            'day' => $this->requestKind === 'recurrence' && ctype_digit($this->requestDay) ? (int) $this->requestDay : null,
            'notes' => $this->requestNotes,
        ]);

        $this->showRequestModal = false;
        unset($this->requests);
        Flux::toast('Solicitação de feriado enviada ao Administrador do Sistema.', variant: 'success');
    }

    public function approveHolidayRequest(int $id, ManageHolidayRegistrationRequest $manage): void
    {
        $target = HolidayRegistrationRequest::query()->findOrFail($id);
        $manage->approve(auth()->user(), $target);
        unset($this->requests);
        Flux::toast('Solicitação aprovada e feriado cadastrado no calendário.', variant: 'success');
    }

    public function openRejectModal(int $id): void
    {
        Gate::authorize('create', Holiday::class);
        $this->rejectTargetId = $id;
        $this->rejectReason = '';
        $this->resetErrorBag();
        $this->showRejectModal = true;
    }

    public function rejectHolidayRequest(ManageHolidayRegistrationRequest $manage): void
    {
        abort_if($this->rejectTargetId === null, 404);
        $target = HolidayRegistrationRequest::query()->findOrFail($this->rejectTargetId);
        $manage->reject(auth()->user(), $target, $this->rejectReason);
        $this->showRejectModal = false;
        $this->rejectTargetId = null;
        $this->rejectReason = '';
        unset($this->requests);
        Flux::toast('Solicitação de feriado recusada.', variant: 'success');
    }

    #[Computed]
    public function selectedRequestCity(): ?City
    {
        if (! ctype_digit($this->requestCityId)) {
            return null;
        }

        return app(HolidayAccess::class)->cities(auth()->user())
            ->when(BrazilianState::tryFrom($this->requestState) !== null, fn ($query) => $query->where('state', $this->requestState))
            ->find((int) $this->requestCityId);
    }

    /** @return Collection<int, City> */
    #[Computed]
    public function requestCities(): Collection
    {
        $search = mb_substr(trim($this->requestCitySearch), 0, 255);
        $state = BrazilianState::tryFrom($this->requestState);
        if (mb_strlen($search) < 2) {
            return new Collection;
        }
        $query = City::search($search);
        if ($state !== null) {
            $query->where('state', $state->value);
        }

        return $this->searchWithRecovery('requestCities', fn () => $query->take(20)->get(), fn () => new Collection);
    }

    /** @return LengthAwarePaginator<int, HolidayRegistrationRequest> */
    #[Computed]
    public function requests(): LengthAwarePaginator
    {
        $affiliation = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'));
        $query = HolidayRegistrationRequest::query()
            ->with(['campus', 'city', 'requestedByAffiliation.user', 'reviewedByAffiliation.user']);

        if (! $this->isAdmin) {
            abort_if($affiliation?->campus_id === null, 403);
            $query->where('campus_id', $affiliation->campus_id);
        }

        if (in_array($this->status, [
            RegistrationRequestStatus::Submitted->value,
            RegistrationRequestStatus::Approved->value,
            RegistrationRequestStatus::Rejected->value,
        ], true)) {
            $query->where('status', $this->status);
        }

        if (in_array($this->kind, ['holiday', 'recurrence'], true)) {
            $query->where('kind', $this->kind);
        }

        return $query->latest('id')->paginate(PageSize::PER_PAGE);
    }
}; ?>

<div class="w-full space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Solicitações de feriado</flux:heading>
            <flux:text class="mt-2">
                {{ $this->isAdmin
                    ? 'Analise os pedidos enviados pelos Setores de Estágio dos campi para inclusão de feriados ou recorrências municipais.'
                    : 'Solicite ao Administrador do Sistema a inclusão de feriados ou recorrências municipais e acompanhe os pedidos enviados pelo seu campus.' }}
            </flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            @can('requestRegistration', Holiday::class)
                <flux:button variant="primary" icon="plus" wire:click="openRequestModal">Solicitar feriado</flux:button>
            @endcan
        </div>
    </div>

    @foreach (['holiday', 'name'] as $error)<flux:error :name="$error" />@endforeach

    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full sm:max-w-xs">
            <x-select wire:model.live="status" label="Situação" :value="$status" :options="[
                'all' => 'Todas as situações',
                RegistrationRequestStatus::Submitted->value => 'Pendentes de análise',
                RegistrationRequestStatus::Approved->value => 'Aprovadas',
                RegistrationRequestStatus::Rejected->value => 'Recusadas',
            ]" />
        </div>
        <div class="w-full sm:max-w-xs">
            <x-select wire:model.live="kind" label="Tipo de cadastro" :value="$kind" :options="[
                'all' => 'Todos os tipos',
                'holiday' => 'Feriado em data específica',
                'recurrence' => 'Recorrência municipal anual',
            ]" />
        </div>
        @if ($this->hasFilters)
            <flux:button variant="outline" wire:click="clearFilters">Limpar filtros</flux:button>
        @endif
    </div>

    @php($requests = $this->requests)
    <x-loading-overlay target="status,kind,clearFilters,approveHolidayRequest,rejectHolidayRequest,submitHolidayRequest,gotoPage,nextPage,previousPage,resetPage" label="Atualizando solicitações…" :dim-while-loading="true">
        @if ($requests->isNotEmpty())
            <flux:table :paginate="$requests">
                <flux:table.columns>
                    <flux:table.column>Feriado</flux:table.column>
                    <flux:table.column>Data / Período</flux:table.column>
                    <flux:table.column>Cidade / UF</flux:table.column>
                    @if ($this->isAdmin)
                        <flux:table.column class="hidden lg:table-cell">Campus / Solicitante</flux:table.column>
                    @endif
                    <flux:table.column>Situação</flux:table.column>
                    @if ($this->isAdmin)
                        <flux:table.column align="end">Ações</flux:table.column>
                    @endif
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($requests as $req)
                        <flux:table.row :key="'holiday-request-'.$req->id">
                            <flux:table.cell class="whitespace-normal">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $req->name }}</div>
                                <flux:text size="sm">{{ $req->kindLabel() }}</flux:text>
                                @if ($req->notes)
                                    <flux:text size="sm" class="mt-1">Observações: {{ $req->notes }}</flux:text>
                                @endif
                                @if ($req->decision_notes)
                                    <flux:text size="sm" class="mt-1 text-red-600 dark:text-red-400">Motivo da recusa: {{ $req->decision_notes }}</flux:text>
                                @endif
                                @if ($this->isAdmin)
                                    <flux:text size="sm" class="mt-1 lg:hidden">{{ $req->campus->name }} · {{ $req->requestedByAffiliation?->user?->name ?? 'Setor de Estágio' }}</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">{{ $req->periodSummary() }}</flux:table.cell>
                            <flux:table.cell class="whitespace-normal">{{ $req->city->name }} / {{ $req->city->state->value }}</flux:table.cell>
                            @if ($this->isAdmin)
                                <flux:table.cell class="hidden whitespace-normal lg:table-cell">
                                    <div>{{ $req->campus->name }}</div>
                                    <flux:text size="sm">{{ $req->requestedByAffiliation?->user?->name ?? 'Setor de Estágio' }} · {{ formatDateTime($req->created_at) }}</flux:text>
                                </flux:table.cell>
                            @endif
                            <flux:table.cell>
                                <flux:badge size="sm" :color="match ($req->status) { RegistrationRequestStatus::Approved => 'green', RegistrationRequestStatus::Rejected => 'red', default => 'amber' }">
                                    {{ $req->status->label() }}
                                </flux:badge>
                            </flux:table.cell>
                            @if ($this->isAdmin)
                                <flux:table.cell align="end">
                                    @if ($req->status === RegistrationRequestStatus::Submitted)
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <flux:button size="sm" variant="primary" icon="check" wire:click="approveHolidayRequest({{ $req->id }})" wire:loading.attr="disabled">Aprovar e cadastrar</flux:button>
                                            <flux:button size="sm" variant="outline" icon="x-mark" wire:click="openRejectModal({{ $req->id }})">Recusar</flux:button>
                                        </div>
                                    @else
                                        <flux:text size="sm">{{ $req->reviewed_at ? formatDateTime($req->reviewed_at) : 'Analisada' }}</flux:text>
                                    @endif
                                </flux:table.cell>
                            @endif
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
                <flux:icon.calendar-days class="mx-auto mb-4 size-8 text-zinc-400" />
                @if ($this->hasFilters)
                    <flux:heading size="lg">Nenhuma solicitação encontrada</flux:heading>
                    <flux:text class="mt-2">Altere os filtros para consultar outras solicitações de feriado.</flux:text>
                    <flux:button class="mt-5" variant="ghost" wire:click="clearFilters">Limpar filtros</flux:button>
                @else
                    <flux:heading size="lg">Nenhuma solicitação de feriado{{ $status === RegistrationRequestStatus::Submitted->value ? ' pendente' : '' }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ $this->isAdmin
                            ? 'Não há solicitações pendentes de análise no momento.'
                            : 'Quando o seu campus solicitar o cadastro de um feriado ou recorrência municipal, o pedido aparecerá aqui.' }}
                    </flux:text>
                @endif
            </div>
        @endif
    </x-loading-overlay>

    @can('requestRegistration', Holiday::class)
        <flux:modal name="request-holiday" wire:model.self="showRequestModal" class="w-full max-w-xl">
            <form wire:submit="submitHolidayRequest" class="flex flex-col gap-5">
                <div class="pr-10">
                    <flux:heading size="lg" level="2">Solicitar cadastro de feriado municipal</flux:heading>
                    <flux:text class="mt-1">Envie os dados do feriado municipal ao Administrador do Sistema para inclusão no calendário.</flux:text>
                </div>

                <x-select wire:model.live="requestKind" label="Tipo de cadastro" :value="$requestKind" :options="['holiday' => 'Data específica (feriado em um ano)', 'recurrence' => 'Recorrência municipal anual (mesmo dia e mês todo ano)']" required />

                <flux:field>
                    <flux:label required>Nome do feriado</flux:label>
                    <flux:input wire:model="requestName" maxlength="255" placeholder="Ex.: Aniversário do Município" required />
                    <flux:error name="name" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-select wire:model.live="requestState" label="UF" :value="$requestState" :options="['' => 'Selecione a UF'] + collect(BrazilianState::options())->map(fn ($label, $value) => $label.' ('.$value.')')->all()" searchable required />
                    <div class="min-w-0">
                        <x-select wire:model.live="requestCityId" label="Cidade" icon="map-pin" placeholder="Selecione a cidade"
                            :value="$requestCityId" :selected-label="$this->selectedRequestCity ? $this->selectedRequestCity->name.' / '.$this->selectedRequestCity->state->value : ''"
                            :options="($this->selectedRequestCity ? [$this->selectedRequestCity->id => $this->selectedRequestCity->name.' / '.$this->selectedRequestCity->state->value] : []) + $this->requestCities->mapWithKeys(fn ($city) => [$city->id => $city->name.' / '.$city->state->value])->all()"
                            :search-unavailable="$this->searchFailed('requestCities')" search-retry="retrySearch('requestCities')" search-target="requestCitySearch,retrySearch"
                            :empty="mb_strlen(trim($requestCitySearch)) < 2 ? 'Digite pelo menos 2 caracteres para buscar.' : 'Nenhuma cidade encontrada.'"
                            :error="$errors->first('city_id')" :invalid="$errors->has('city_id')"
                            searchable required wire:key="req-holiday-city-{{ $requestState }}">
                            <x-slot:search>
                                <flux:input name="" wire:model.live.debounce.500ms="requestCitySearch" wire:ignore.self placeholder="Buscar cidade…" aria-label="Buscar cidade" icon="magnifying-glass" size="sm" maxlength="255"
                                    role="combobox" aria-autocomplete="list" x-bind:aria-expanded="open" x-bind:aria-controls="$refs.list.id" x-bind:aria-activedescendant="activeId()" />
                                <span wire:loading wire:target="requestCitySearch" class="block px-2 py-1 text-xs text-zinc-500" role="status">Buscando cidades…</span>
                            </x-slot:search>
                        </x-select>
                    </div>
                </div>

                @if ($requestKind === 'holiday')
                    <flux:field>
                        <flux:label required>Data do feriado</flux:label>
                        <flux:input type="date" wire:model="requestDate" required />
                        <flux:error name="date" />
                    </flux:field>
                @else
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label required>Dia</flux:label>
                            <flux:input type="number" min="1" max="31" wire:model="requestDay" placeholder="Ex.: 30" required />
                            <flux:error name="day" />
                        </flux:field>
                        <flux:field>
                            <flux:label required>Mês</flux:label>
                            <flux:input type="number" min="1" max="12" wire:model="requestMonth" placeholder="Ex.: 5" required />
                            <flux:error name="month" />
                        </flux:field>
                    </div>
                @endif

                <flux:field>
                    <flux:label>Observações ou fundamentação (opcional)</flux:label>
                    <flux:textarea wire:model="requestNotes" rows="3" maxlength="2000" placeholder="Ex.: Lei Municipal nº ..." />
                    <flux:error name="notes" />
                </flux:field>

                <div class="flex flex-wrap justify-end gap-3">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">Cancelar</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" icon="paper-airplane" wire:loading.attr="disabled" wire:target="submitHolidayRequest">
                        Enviar solicitação
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan

    @if ($this->isAdmin)
        <flux:modal name="reject-holiday-request" wire:model.self="showRejectModal" class="md:w-lg">
            <form wire:submit="rejectHolidayRequest" class="flex flex-col gap-5">
                <div class="pr-10">
                    <flux:heading size="lg" level="2">Recusar solicitação de feriado</flux:heading>
                    <flux:text class="mt-1">Informe o motivo da recusa para orientar o Setor de Estágio solicitante.</flux:text>
                </div>

                <flux:field>
                    <flux:label required>Motivo da recusa</flux:label>
                    <flux:textarea wire:model="rejectReason" rows="3" maxlength="2000" required />
                    <flux:error name="decision_notes" />
                </flux:field>

                <div class="flex flex-wrap justify-end gap-3">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">Cancelar</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="rejectHolidayRequest">
                        Confirmar recusa
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
