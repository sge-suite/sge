<?php

use App\Helpers\BrazilianAddressHelper;
use App\Helpers\BrazilianContactHelper;
use App\Helpers\BrazilianDocumentHelper;
use App\Models\Address;
use App\Models\Campus;
use App\Support\ActivityAccess;
use App\Support\ActivityHistory;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Detalhes do campus')] class extends Component
{
    use WithPagination;

    #[Locked]
    public int $campusId;

    public string $currentPassword = '';

    public string $deletionPassword = '';

    public bool $showDeactivation = false;

    public bool $showDeletion = false;

    public bool $showReactivation = false;

    public function boot(): void
    {
        Gate::authorize('viewAdministration', Campus::class);
    }

    public function mount(Campus $campus): void
    {
        $this->campusId = $campus->id;
        $this->showDeactivation = $this->getErrorBag()->has('current_password');
    }

    public function updatedShowDeactivation(bool $show): void
    {
        if (! $show) {
            $this->reset('currentPassword');
            $this->resetValidation('currentPassword');
            $this->resetValidation('current_password');
        }
    }

    public function updatedShowDeletion(bool $show): void
    {
        if (! $show) {
            $this->reset('deletionPassword');
            $this->resetValidation('deletionPassword');
            $this->resetValidation('deletion');
        }
    }

    public function closeDeactivationModal(): void
    {
        $this->showDeactivation = false;
        $this->reset('currentPassword');
        $this->resetValidation('currentPassword');
        $this->resetValidation('current_password');
    }

    public function closeDeletionModal(): void
    {
        $this->showDeletion = false;
        $this->reset('deletionPassword');
        $this->resetValidation('deletionPassword');
        $this->resetValidation('deletion');
    }

    public function deactivateCampus(): void
    {
        Gate::authorize('deactivate', $this->campus);

        $this->validate([
            'currentPassword' => ['required', 'string', 'current_password'],
        ], [
            'currentPassword.required' => 'Informe sua senha atual.',
            'currentPassword.current_password' => 'A senha informada está incorreta.',
        ]);

        DB::transaction(function (): void {
            $campus = Campus::query()->lockForUpdate()->findOrFail($this->campusId);
            Gate::authorize('deactivate', $campus);
            $campus->deactivate();
        });

        unset($this->campus);

        $this->closeDeactivationModal();

        Flux::toast(variant: 'success', text: 'Campus desativado com sucesso.');
    }

    public function deleteCampus(): void
    {
        Gate::authorize('delete', $this->campus);
        $this->resetValidation('deletion');

        $this->validate([
            'deletionPassword' => ['required', 'string', 'current_password'],
        ], [
            'deletionPassword.required' => 'Informe sua senha atual.',
            'deletionPassword.current_password' => 'A senha informada está incorreta.',
        ]);

        $deleted = DB::transaction(function (): bool {
            $campus = Campus::query()->lockForUpdate()->findOrFail($this->campusId);
            Gate::authorize('delete', $campus);
            $address = Address::query()->lockForUpdate()->findOrFail($campus->address_id);

            if ($campus->hasLinkedRecords()) {
                return false;
            }

            $campus->forceDelete();
            $address->delete();

            return true;
        });

        if (! $deleted) {
            $this->addError('deletion', 'Não é possível apagar este campus enquanto houver cadastros ou registros vinculados.');

            return;
        }

        session()->flash('status', 'Campus apagado com sucesso.');
        $this->redirectRoute('campuses.index', navigate: true);
    }

    #[Computed]
    public function campus(): Campus
    {
        $campus = Campus::with('address.city')->findOrFail($this->campusId);
        Gate::authorize('view', $campus);

        return $campus;
    }

    /** @return LengthAwarePaginator<int, array{id: int, actor: string, subject: string, event: string, occurred_at: string}>|null */
    #[Computed]
    public function activityHistory(): ?LengthAwarePaginator
    {
        $query = app(ActivityAccess::class)->forCurrentContext(auth()->user(), app('session.store'));

        return $query === null ? null : app(ActivityHistory::class)->forSubject($this->campus, $query);
    }
}; ?>

<div class="w-full space-y-8">
    @php($campus = $this->campus)
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('campuses.index')" wire:navigate>Campi</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $campus->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1" class="break-words">{{ $campus->name }}</flux:heading>
                <flux:badge size="sm" :color="$campus->deactivated_at === null ? 'green' : 'zinc'">{{ $campus->deactivated_at === null ? 'Ativo' : 'Desativado' }}</flux:badge>
            </div>
            <flux:text class="mt-2">{{ $campus->address->city->name }} · {{ $campus->address->city->state->label() }}</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <flux:button variant="outline" icon="arrow-left" :href="route('campuses.index')" wire:navigate>Voltar</flux:button>
            @if ($campus->deactivated_at === null)
                <flux:button icon="pencil-square" :href="route('campuses.edit', $campus)" wire:navigate>Editar campus</flux:button>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div x-data x-init="$nextTick(() => $flux.toast(@js(session('status')), { variant: 'success' }))"></div>
    @endif
    @if ($campus->deactivated_at !== null)
        <flux:callout icon="information-circle" heading="Campus somente para leitura">
            <flux:callout.text>Desativado em {{ formatDateTime($campus->deactivated_at) }}. Reative o campus para permitir novas alterações.</flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-8 lg:grid-cols-2">
        <section aria-labelledby="campus-contact-heading" class="border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <flux:heading id="campus-contact-heading" size="lg" level="2">Dados institucionais</flux:heading>
            <dl class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">CNPJ</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ BrazilianDocumentHelper::formatCnpj($campus->cnpj) }}</dd></div>
                <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Telefone</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ BrazilianContactHelper::formatPhone($campus->phone) }}</dd></div>
            </dl>
        </section>
        <section aria-labelledby="campus-address-heading" class="border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <flux:heading id="campus-address-heading" size="lg" level="2">Endereço</flux:heading>
            <div class="mt-5 space-y-1 text-sm text-zinc-900 dark:text-white">
                <p>{{ $campus->address->street }}, {{ $campus->address->number }}</p>
                <p>{{ $campus->address->neighborhood }}</p>
                <p>{{ $campus->address->city->name }} / {{ $campus->address->city->state->value }}</p>
                <p class="text-zinc-500 dark:text-zinc-400">CEP: {{ BrazilianAddressHelper::formatCep($campus->address->zip_code) }}</p>
            </div>
        </section>
        <section aria-labelledby="campus-representative-heading" class="border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <flux:heading id="campus-representative-heading" size="lg" level="2">Representante legal</flux:heading>
            <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Nome</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $campus->legal_representative_name ?: 'Não informado' }}</dd></div>
                <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Cargo</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $campus->legal_representative_position ?: 'Não informado' }}</dd></div>
            </dl>
        </section>
        <section aria-labelledby="campus-insurance-heading" class="border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <flux:heading id="campus-insurance-heading" size="lg" level="2">Seguro</flux:heading>
            <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Seguradora</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $campus->insurance_company_name ?: 'Não informada' }}</dd></div>
                <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Apólice</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $campus->insurance_policy_number ?: 'Não informada' }}</dd></div>
            </dl>
        </section>
    </div>

    <section class="flex flex-col justify-between gap-5 rounded-xl border border-zinc-200 p-5 sm:flex-row sm:items-center dark:border-zinc-700" aria-labelledby="campus-availability-heading">
        <div>
            <flux:heading id="campus-availability-heading" level="2">Disponibilidade do campus</flux:heading>
            <flux:text class="mt-1 max-w-xl">{{ $campus->deactivated_at === null ? 'A desativação impede alterações nos recursos do campus e preserva os vínculos e os processos existentes.' : 'A reativação permite novas alterações. Processamentos anteriores não são retomados automaticamente.' }}</flux:text>
        </div>
        @if ($campus->deactivated_at === null)
            <flux:button variant="danger" x-on:click="$wire.showDeactivation = true">Desativar campus</flux:button>
        @else
            <flux:button variant="primary" x-on:click="$wire.showReactivation = true">Reativar campus</flux:button>
        @endif
    </section>

    @if (! $campus->hasLinkedRecords())
        <section class="flex flex-col justify-between gap-5 rounded-xl border border-red-200 p-5 sm:flex-row sm:items-center dark:border-red-900" aria-labelledby="campus-deletion-heading">
            <div>
                <flux:heading id="campus-deletion-heading" level="2">Apagar campus</flux:heading>
                <flux:text class="mt-1 max-w-xl">Apaga permanentemente o campus e seu endereço. A opção fica disponível apenas quando não há cadastros ou registros vinculados.</flux:text>
            </div>
            <flux:button data-campus-delete-action variant="danger" icon="trash" x-on:click="$wire.showDeletion = true">Apagar campus</flux:button>
        </section>
    @endif

    @if ($campus->deactivated_at === null)
        <flux:modal name="deactivate-campus" wire:model.self="showDeactivation" class="md:w-md">
            <form wire:submit="deactivateCampus" class="space-y-6">
                <div>
                    <flux:heading size="lg" level="2">Desativar campus</flux:heading>
                    <flux:text class="mt-2">Confirme sua senha para desativar {{ $campus->name }}. O campus ficará somente para leitura.</flux:text>
                </div>
                <flux:input wire:model="currentPassword" label="Sua senha atual" type="password" autocomplete="current-password" required viewable />
                @if ($errors->has('current_password') && ! $errors->has('currentPassword'))
                    <flux:error name="current_password" />
                @endif
                <div class="flex flex-wrap justify-end gap-3">
                    <flux:modal.close><flux:button type="button" variant="outline" wire:click="closeDeactivationModal">Cancelar</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger">Desativar campus</flux:button>
                </div>
            </form>
        </flux:modal>
    @else
        <flux:modal name="reactivate-campus" wire:model.self="showReactivation" class="md:w-md">
            <form method="POST" action="{{ route('campuses.reactivate', $campus) }}" class="space-y-6" x-data="{ submitting: false }" x-on:submit="submitting = true">
                @csrf
                @method('PATCH')
                <div>
                    <flux:heading size="lg" level="2">Reativar campus</flux:heading>
                    <flux:text class="mt-2">{{ $campus->name }} voltará a aceitar alterações e novos cadastros. Deseja continuar?</flux:text>
                </div>
                <div class="flex flex-wrap justify-end gap-3">
                    <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" :loading="false" x-bind:disabled="submitting" x-bind:aria-busy="submitting"><flux:icon.loading x-show="submitting" x-cloak class="size-4" />Reativar campus</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    <flux:modal name="delete-campus" wire:model.self="showDeletion" class="md:w-md">
        <form wire:submit="deleteCampus" class="space-y-6">
            <div>
                <flux:heading size="lg" level="2">Apagar campus</flux:heading>
                <flux:text class="mt-2">Esta ação é permanente e também apagará o endereço de {{ $campus->name }}. Confirme sua senha para continuar.</flux:text>
            </div>
            <flux:input wire:model="deletionPassword" label="Sua senha atual" type="password" autocomplete="current-password" required viewable />
            <flux:error name="deletion" />
            <div class="flex flex-wrap justify-end gap-3">
                <flux:modal.close><flux:button type="button" variant="outline" wire:click="closeDeletionModal">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="deleteCampus">Apagar campus</flux:button>
            </div>
        </form>
    </flux:modal>

    @can('viewAny', \Spatie\Activitylog\Models\Activity::class)
        <x-audit.activity-history :activities="$this->activityHistory" />
    @endcan
</div>
