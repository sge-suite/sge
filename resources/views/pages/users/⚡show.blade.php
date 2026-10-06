<?php

use App\Models\User;
use App\Models\Affiliation;
use App\Support\ActiveAffiliationContext;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Detalhes do usuário')] class extends Component
{
    #[Locked]
    public int $userId;

    #[Locked]
    public ?int $selectedAffiliationId = null;

    #[Locked]
    public string $operation = '';

    public bool $showConfirmation = false;

    public function boot(): void
    {
        Gate::authorize('viewAny', User::class);
    }

    public function mount(User $user): void
    {
        $this->userId = $user->id;
        if (session()->has('errors') && request('operation') === 'delete_account') {
            $this->openAccountDeletion();
        } elseif (session()->has('errors') && in_array(request('operation'), ['deactivate', 'reactivate', 'delete'], true) && ctype_digit((string) request('affiliation'))) {
            $this->openConfirmation((int) request('affiliation'), request('operation'));
        }
    }

    #[Computed]
    public function account(): User
    {
        $user = User::with(['affiliations' => fn ($query) => $query->administrative()->with('campus')->orderBy('id')])->findOrFail($this->userId);
        Gate::authorize('view', $user);

        return $user;
    }

    #[Computed]
    public function activeAffiliationId(): ?int
    {
        return app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'))?->id;
    }

    public function openAccountDeletion(): void
    {
        Gate::authorize('delete', $this->account);
        $this->selectedAffiliationId = null;
        $this->operation = 'delete_account';
        $this->showConfirmation = true;
    }

    public function openConfirmation(int $affiliationId, string $operation): void
    {
        abort_unless(in_array($operation, ['deactivate', 'reactivate', 'delete'], true), 404);
        $affiliation = $this->account->affiliations()->findOrFail($affiliationId);
        Gate::authorize($operation, $affiliation);
        $this->selectedAffiliationId = $affiliation->id;
        $this->operation = $operation;
        $this->showConfirmation = true;
    }

    #[Computed]
    public function selectedAffiliation(): ?Affiliation
    {
        if ($this->selectedAffiliationId === null || ! $this->showConfirmation) {
            return null;
        }
        $affiliation = $this->account->affiliations()->findOrFail($this->selectedAffiliationId);
        Gate::authorize($this->operation, $affiliation);

        return $affiliation;
    }

    #[Computed]
    public function confirmationAction(): string
    {
        if ($this->operation === 'delete_account') {
            Gate::authorize('delete', $this->account);

            return route('users.destroy', $this->account);
        }

        return route('users.affiliations.'.($this->operation === 'delete' ? 'destroy' : $this->operation), [$this->account, $this->selectedAffiliation]);
    }
}; ?>

<div class="w-full space-y-8">
    @php($account = $this->account)
    @if (session('status'))
        <div x-data x-init="$nextTick(() => $flux.toast(@js(session('status')), { variant: 'success' }))"></div>
    @endif
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('users.index')" wire:navigate>Usuários</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $account->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ $account->name }}</flux:heading>
        <flux:button variant="outline" icon="arrow-left" :href="route('users.index')" wire:navigate>Voltar</flux:button>
    </div>

    <section class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700" aria-labelledby="account-access-heading">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading id="account-access-heading" size="lg" level="2">Dados de acesso</flux:heading>
                <flux:text class="mt-1">Identificação da pessoa e endereço usado para entrar no sistema.</flux:text>
            </div>
            <flux:button icon="pencil-square" :href="route('users.edit', $account)" wire:navigate>Editar dados de acesso</flux:button>
        </div>
        <dl class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="text-sm text-zinc-500">Nome completo</dt><dd class="mt-1 font-medium">{{ $account->name }}</dd></div>
            <div><dt class="text-sm text-zinc-500">CPF</dt><dd class="mt-1 font-medium">{{ $account->cpf }}</dd></div>
            <div><dt class="text-sm text-zinc-500">E-mail de login</dt><dd class="mt-1 break-all font-medium">{{ $account->email }}</dd></div>
        </dl>
    </section>

    <section class="space-y-5" aria-labelledby="administrative-affiliations-heading">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading id="administrative-affiliations-heading" size="lg" level="2">Vínculos administrativos</flux:heading>
                <flux:text class="mt-1">Gerencie função, campus, matrícula e contato institucional.</flux:text>
            </div>
            <flux:button icon="plus" variant="primary" :href="route('users.affiliations.create', $account)" wire:navigate>Adicionar vínculo</flux:button>
        </div>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Tipo / campus</flux:table.column><flux:table.column>Matrícula</flux:table.column><flux:table.column>E-mail do vínculo</flux:table.column><flux:table.column>Situação</flux:table.column><flux:table.column>Ações do vínculo</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($account->affiliations as $affiliation)
                    <flux:table.row :key="$affiliation->id">
                        <flux:table.cell class="whitespace-normal">
                            <div class="font-medium">{{ $affiliation->type->label() }}</div>
                            <flux:text size="sm">{{ $affiliation->campus?->name ?? 'Todos os campi' }}</flux:text>
                            @if ($this->activeAffiliationId === $affiliation->id)
                                <flux:badge size="sm" class="mt-2">Vínculo em uso</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $affiliation->registration_number }}</flux:table.cell>
                        <flux:table.cell>{{ $affiliation->email }}</flux:table.cell>
                        <flux:table.cell><flux:badge :color="$affiliation->deactivated_at === null ? 'green' : 'zinc'" size="sm">{{ $affiliation->deactivated_at === null ? 'Ativo' : 'Desativado' }}</flux:badge></flux:table.cell>
                        <flux:table.cell>
                            @can('update', $affiliation)
                                <div class="flex flex-wrap gap-2">
                                    <flux:button size="sm" icon="pencil-square" :href="route('users.affiliations.edit', [$account, $affiliation])" wire:navigate>Editar vínculo</flux:button>
                                    @if ($affiliation->deactivated_at === null)
                                        @can('deactivate', $affiliation)
                                            <flux:button size="sm" icon="no-symbol" wire:click="openConfirmation({{ $affiliation->id }}, 'deactivate')">Desativar</flux:button>
                                        @else
                                            <flux:tooltip content="Este vínculo está em uso. Outro Administrador do Sistema pode desativá-lo.">
                                                <flux:button size="sm" icon="no-symbol" disabled>Desativar</flux:button>
                                            </flux:tooltip>
                                        @endcan
                                    @else
                                        <flux:button size="sm" icon="check-circle" wire:click="openConfirmation({{ $affiliation->id }}, 'reactivate')">Reativar</flux:button>
                                    @endif
                                    @can('delete', $affiliation)
                                        <flux:button size="sm" icon="trash" variant="danger" wire:click="openConfirmation({{ $affiliation->id }}, 'delete')">Excluir vínculo</flux:button>
                                    @endcan
                                </div>
                            @else
                                <flux:text size="sm">Somente leitura</flux:text>
                            @endcan
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        @if ($account->affiliations->isEmpty())
            <flux:text>Nenhum vínculo administrativo. Adicione um vínculo ou exclua a conta se não houver registros associados.</flux:text>
        @endif
        <flux:text size="sm">Para mudar a função ou o campus, adicione outro vínculo. Campi desativados ficam somente para leitura. O vínculo em uso e o último Administrador do Sistema ativo não podem ser removidos.</flux:text>
    </section>

    @can('delete', $account)
        <section class="flex flex-wrap items-center justify-between gap-4 border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <div><flux:heading level="2">Excluir conta</flux:heading><flux:text class="mt-1">Remove a conta e seus vínculos, somente se não houver registros associados.</flux:text></div>
            <flux:button icon="trash" variant="danger" wire:click="openAccountDeletion">Excluir conta</flux:button>
        </section>
    @endcan

    <flux:modal wire:model.self="showConfirmation" class="max-w-lg">
        @if ($showConfirmation)
            <form method="POST" action="{{ $this->confirmationAction }}" class="space-y-5" x-data="{ submitting: false }" x-on:submit="submitting = true">
                @csrf
                @method(in_array($operation, ['delete', 'delete_account'], true) ? 'DELETE' : 'PATCH')
                <flux:heading size="lg">{{ match ($operation) { 'deactivate' => 'Desativar vínculo', 'reactivate' => 'Reativar vínculo', 'delete' => 'Excluir vínculo', default => 'Excluir conta' } }}</flux:heading>
                @if ($operation === 'delete_account')
                    <flux:text>A conta de {{ $account->name }} e seus vínculos serão removidos. A exclusão só será permitida sem registros associados. Enviaremos um aviso aos e-mails cadastrados.</flux:text>
                @else
                    <flux:text>{{ $this->selectedAffiliation->type->label() }} · matrícula {{ $this->selectedAffiliation->registration_number }}</flux:text>
                    <flux:text>{{ match ($operation) { 'deactivate' => 'O acesso por este vínculo será encerrado, mantendo o histórico. Enviaremos um aviso à conta e ao e-mail do vínculo.', 'delete' => 'O vínculo será removido se não possuir registros associados. Enviaremos um aviso à conta e ao e-mail do vínculo.', default => 'O acesso por este vínculo será restabelecido.' } }}</flux:text>
                @endif
                @if ($operation !== 'reactivate')
                    <flux:input type="password" name="current_password" label="Sua senha atual" autocomplete="current-password" error:name="current_password" />
                @endif
                <flux:checkbox name="confirmed" value="1" label="Confirmo esta alteração" />
                <flux:error name="confirmed" />
                <flux:error name="type" />
                <flux:error name="affiliation" />
                <flux:error name="account" />
                <div class="flex justify-end gap-3">
                    <flux:button type="button" wire:click="$set('showConfirmation', false)">Cancelar</flux:button>
                    <flux:button type="submit" :variant="in_array($operation, ['delete', 'delete_account'], true) ? 'danger' : 'primary'" x-bind:disabled="submitting">Confirmar</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
