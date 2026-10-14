<?php

use App\Actions\ManageGrantingParty;
use App\Models\GrantingParty;
use App\Support\ActivityAccess;
use App\Support\ActivityHistory;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Detalhes da parte concedente')] class extends Component
{
    use WithPagination;

    #[Locked]
    public int $grantingPartyId;

    public string $currentPassword = '';

    public bool $showDeactivation = false;

    public bool $showReactivation = false;

    public bool $showDeletion = false;

    public string $deletionPassword = '';

    public function boot(): void
    {
        Gate::authorize('viewAny', GrantingParty::class);
        if (isset($this->grantingPartyId)) {
            Gate::authorize('view', GrantingParty::findOrFail($this->grantingPartyId));
        }
    }

    public function mount(GrantingParty $grantingParty): void
    {
        Gate::authorize('view', $grantingParty);
        $this->grantingPartyId = $grantingParty->id;
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

    public function closeDeactivationModal(): void
    {
        $this->showDeactivation = false;
        $this->reset('currentPassword');
        $this->resetValidation('currentPassword');
        $this->resetValidation('current_password');
    }

    public function deactivateGrantingParty(ManageGrantingParty $manageGrantingParty): void
    {
        Gate::authorize('deactivate', $this->grantingParty);
        $this->validate([
            'currentPassword' => ['required', 'string', 'current_password'],
        ], [
            'currentPassword.required' => 'Informe sua senha atual.',
            'currentPassword.current_password' => 'A senha informada está incorreta.',
        ]);

        $manageGrantingParty->handle(auth()->user(), $this->grantingParty, 'deactivate', ['current_password' => $this->currentPassword]);
        unset($this->grantingParty);
        $this->closeDeactivationModal();
        Flux::toast(variant: 'success', text: 'Parte concedente desativada com sucesso.');
    }

    public function reactivateGrantingParty(ManageGrantingParty $manageGrantingParty): void
    {
        Gate::authorize('reactivate', $this->grantingParty);
        $manageGrantingParty->handle(auth()->user(), $this->grantingParty, 'reactivate');
        unset($this->grantingParty);
        $this->showReactivation = false;
        Flux::toast(variant: 'success', text: 'Parte concedente reativada com sucesso.');
    }

    public function updatedShowDeletion(bool $show): void
    {
        if (! $show) { $this->closeDeletionModal(); }
    }

    public function closeDeletionModal(): void
    {
        $this->showDeletion = false;
        $this->reset('deletionPassword');
        $this->resetValidation('deletionPassword');
        $this->resetValidation('deletion');
    }

    public function deleteGrantingParty(ManageGrantingParty $manageGrantingParty): void
    {
        Gate::authorize('delete', $this->grantingParty);
        $this->resetValidation('deletion');
        $this->validate([
            'deletionPassword' => ['required', 'string', 'current_password'],
        ], [
            'deletionPassword.required' => 'Informe sua senha atual.',
            'deletionPassword.current_password' => 'A senha informada está incorreta.',
        ]);

        $manageGrantingParty->handle(auth()->user(), $this->grantingParty, 'delete', ['current_password' => $this->deletionPassword]);
        session()->flash('status', 'Parte concedente excluída com sucesso.');
        $this->redirectRoute('granting-parties.index', navigate: true);
    }

    #[Computed]
    public function grantingParty(): GrantingParty
    {
        $grantingParty = GrantingParty::with(['campus', 'address.city'])->findOrFail($this->grantingPartyId);
        Gate::authorize('view', $grantingParty);

        return $grantingParty;
    }

    /** @return LengthAwarePaginator<int, array{id: int, actor: string, subject: string, event: string, occurred_at: string}>|null */
    #[Computed]
    public function activityHistory(): ?LengthAwarePaginator
    {
        $query = app(ActivityAccess::class)->forCurrentContext(auth()->user(), app('session.store'));

        return $query === null ? null : app(ActivityHistory::class)->forSubject($this->grantingParty, $query);
    }
}; ?>

<div class="w-full space-y-8">
    @php($grantingParty = $this->grantingParty)
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('granting-parties.index')" wire:navigate>Partes concedentes</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $grantingParty->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1" class="wrap-break-word">{{ $grantingParty->name }}</flux:heading>
                <flux:badge size="sm" :color="$grantingParty->deactivated_at === null ? 'green' : 'zinc'">{{ $grantingParty->deactivated_at === null ? 'Ativo' : 'Desativado' }}</flux:badge>
            </div>
            <flux:text class="mt-2">{{ $grantingParty->campus->name }}</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <flux:button variant="outline" icon="arrow-left" :href="route('granting-parties.index')" wire:navigate>Voltar</flux:button>
            @can('update', $grantingParty)
                <flux:button icon="pencil-square" :href="route('granting-parties.edit', $grantingParty)" wire:navigate>Editar parte concedente</flux:button>
            @endcan
        </div>
    </div>
    @if (session('status'))
        <div x-data x-init="$nextTick(() => $flux.toast(@js(session('status')), { variant: 'success' }))"></div>
    @endif
    @if ($grantingParty->campus->deactivated_at !== null)
        <flux:callout icon="information-circle" heading="Campus somente para leitura">
            <flux:callout.text>O campus está desativado. As partes concedentes podem ser consultadas, mas não podem ser alteradas.</flux:callout.text>
        </flux:callout>
    @endif
    @if ($grantingParty->deactivated_at !== null)
        <flux:callout icon="information-circle" heading="Parte concedente desativada">
            <flux:callout.text>Desativada em {{ formatDateTime($grantingParty->deactivated_at) }}. Os vínculos e registros históricos foram preservados.</flux:callout.text>
        </flux:callout>
    @endif
    <section class="border-t border-zinc-200 pt-6 dark:border-zinc-700">
        <flux:heading size="lg">Dados cadastrais</flux:heading>
        <dl class="mt-5 grid gap-6 md:grid-cols-2">
            @foreach (['Documento' => \App\Helpers\BrazilianDocumentHelper::formatCpfCnpj($grantingParty->document_number), 'Representante' => $grantingParty->representative_name, 'Cargo' => $grantingParty->representative_role, 'Telefone' => \App\Helpers\BrazilianContactHelper::formatPhone($grantingParty->phone), 'E-mail' => $grantingParty->email, 'Área de atuação' => $grantingParty->field_of_activity, 'Conselho profissional' => $grantingParty->professional_council, 'Registro no conselho' => $grantingParty->council_registration_number, 'Processo de credenciamento' => $grantingParty->credentialing_process_number] as $label => $value)
                <div wire:key="detail-{{ $loop->index }}"><dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="mt-1 text-sm">{{ $value ?? 'Não informado' }}</dd></div>
            @endforeach
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Endereço</dt><dd class="mt-1 text-sm">{{ $grantingParty->address->street }}, {{ $grantingParty->address->number }} · {{ $grantingParty->address->neighborhood }} · {{ $grantingParty->address->city->name }} / {{ $grantingParty->address->city->state->value }} · {{ \App\Helpers\BrazilianAddressHelper::formatCep($grantingParty->address->zip_code) }}</dd></div>
        </dl>
    </section>
    @if ($grantingParty->hasLinkedRecords())
        <flux:callout heading="Exclusão indisponível"><flux:callout.text>Esta concedente possui registros vinculados. Você pode desativá-la para impedir novas associações.</flux:callout.text></flux:callout>
    @endif
    @if (auth()->user()->can('deactivate', $grantingParty) || auth()->user()->can('reactivate', $grantingParty))
        <section class="flex flex-col justify-between gap-5 rounded-xl border border-zinc-200 p-5 sm:flex-row sm:items-center dark:border-zinc-700" aria-labelledby="grantingParty-availability-heading">
            <div>
                <flux:heading id="grantingParty-availability-heading" level="2">Disponibilidade da parte concedente</flux:heading>
                <flux:text class="mt-1 max-w-xl">{{ $grantingParty->deactivated_at === null ? 'A desativação impede novas atribuições da parte concedente e preserva os vínculos existentes.' : 'A reativação permite novas atribuições da parte concedente. Os vínculos e registros anteriores são preservados.' }}</flux:text>
            </div>
            @can('deactivate', $grantingParty)
                <flux:button variant="danger" icon="no-symbol" x-on:click="$wire.showDeactivation = true">Desativar parte concedente</flux:button>
            @endcan
            @can('reactivate', $grantingParty)
                <flux:button variant="primary" x-on:click="$wire.showReactivation = true">Reativar parte concedente</flux:button>
            @endcan
        </section>
    @endif
    @can('delete', $grantingParty)
        @if (! $grantingParty->hasLinkedRecords())
            <section class="flex flex-col justify-between gap-5 rounded-xl border border-red-200 p-5 sm:flex-row sm:items-center dark:border-red-900" aria-labelledby="grantingParty-deletion-heading">
                <div>
                    <flux:heading id="grantingParty-deletion-heading" level="2">Excluir parte concedente</flux:heading>
                    <flux:text class="mt-1 max-w-xl">Exclui permanentemente a parte concedente e seu endereço, quando não há registros associados. Endereços utilizados por outros registros e a auditoria serão preservados.</flux:text>
                </div>
                <flux:button data-grantingParty-delete-action variant="danger" icon="trash" wire:click="$set('showDeletion', true)">Excluir parte concedente</flux:button>
            </section>
        @endif
        <flux:modal name="delete-grantingParty" wire:model.self="showDeletion" class="md:w-md">
            <form wire:submit="deleteGrantingParty" class="space-y-6">
                <div>
                    <flux:heading size="lg" level="2">Excluir parte concedente</flux:heading>
                    <flux:text class="mt-2">Esta ação excluirá permanentemente {{ $grantingParty->name }} e seu endereço próprio, somente se não houver vínculos ou registros associados. Confirme sua senha para continuar.</flux:text>
                </div>
                <flux:input wire:model="deletionPassword" label="Sua senha atual" type="password" autocomplete="current-password" required viewable />
                <flux:error name="deletion" />
                <div class="flex flex-wrap justify-end gap-3">
                    <flux:modal.close><flux:button type="button" variant="outline" wire:click="closeDeletionModal">Cancelar</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="deleteGrantingParty">Excluir parte concedente</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
    @can('deactivate', $grantingParty)
    <flux:modal name="deactivate-grantingParty" wire:model.self="showDeactivation" class="md:w-md">
        <form wire:submit="deactivateGrantingParty" class="space-y-6">
            <div>
                <flux:heading size="lg" level="2">Desativar parte concedente</flux:heading>
                <flux:text class="mt-2">Confirme sua senha para desativar {{ $grantingParty->name }}. Novas atribuições serão impedidas e os vínculos existentes serão preservados.</flux:text>
            </div>
            <flux:input wire:model="currentPassword" label="Sua senha atual" type="password" autocomplete="current-password" required viewable />
            @if ($errors->has('current_password') && ! $errors->has('currentPassword'))
                <flux:error name="current_password" />
            @endif
            <div class="flex flex-wrap justify-end gap-3">
                <flux:modal.close><flux:button type="button" variant="outline" wire:click="closeDeactivationModal">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" icon="no-symbol" wire:loading.attr="disabled" wire:target="deactivateGrantingParty">Desativar parte concedente</flux:button>
            </div>
        </form>
    </flux:modal>
    @endcan
    @can('reactivate', $grantingParty)
    <flux:modal wire:model="showReactivation" class="md:w-96">
        <div class="space-y-6">
            <div><flux:heading size="lg">Reativar parte concedente</flux:heading><flux:text class="mt-2">Deseja reativar {{ $grantingParty->name }} para permitir novas atribuições?</flux:text></div>
            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showReactivation', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="reactivateGrantingParty">Confirmar reativação</flux:button>
            </div>
        </div>
    </flux:modal>
    @endcan
    @can('viewAny', \Spatie\Activitylog\Models\Activity::class)
        <x-audit.activity-history :activities="$this->activityHistory" />
    @endcan
</div>
