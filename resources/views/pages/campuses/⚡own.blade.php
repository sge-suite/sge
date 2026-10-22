<?php

use App\Models\Campus;
use App\Helpers\BrazilianAddressHelper;
use App\Helpers\BrazilianContactHelper;
use App\Helpers\BrazilianDocumentHelper;
use App\Support\ActivityAccess;
use App\Support\ActivityHistory;
use App\Support\ActiveAffiliationContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Meu campus')] class extends Component
{
    use WithPagination;

    #[Locked]
    public int $campusId;

    public function boot(): void
    {
        Gate::authorize('viewOwn', Campus::class);
        if (isset($this->campusId)) {
            Gate::authorize('view', Campus::with('address.city')->findOrFail($this->campusId));
        }
    }

    public function mount(ActiveAffiliationContext $context): void
    {
        $affiliation = $context->currentFor(auth()->user(), app('session.store'));
        abort_if($affiliation?->campus_id === null, 403);
        $this->campusId = $affiliation->campus_id;
        Gate::authorize('view', $this->campus);
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
        <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>Painel</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Meu campus</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    @if (session('status'))
        <div hidden x-data x-init="$nextTick(() => $flux.toast(@js(session('status')), { variant: 'success' }))"></div>
    @endif
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><flux:heading size="xl" level="1">Meu campus</flux:heading><flux:text class="mt-2">{{ $campus->name }}</flux:text></div>
        @can('update', $campus)
            <flux:button variant="primary" icon="pencil-square" :href="route('campuses.own.edit')" wire:navigate>Editar campus</flux:button>
        @endcan
    </div>
    @if ($campus->deactivated_at !== null)
        <flux:callout icon="information-circle" heading="Campus somente para leitura">O campus está desativado. Seus dados podem ser consultados, mas não podem ser alterados.</flux:callout>
    @endif
    <div class="grid gap-8 lg:grid-cols-2">
        <section aria-labelledby="campus-contact-heading" class="border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <flux:heading id="campus-contact-heading" size="lg" level="2">Dados institucionais</flux:heading>
            <dl class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">CNPJ</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ BrazilianDocumentHelper::formatCnpj($campus->cnpj) }}</dd></div>
                <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Telefone</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ BrazilianContactHelper::formatPhone($campus->phone) }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-sm text-zinc-500 dark:text-zinc-400">E-mail institucional do Setor de Estágio</dt><dd class="mt-1 wrap-break-word text-sm text-zinc-900 dark:text-white">{{ $campus->internship_office_email }}</dd></div>
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

    @can('viewAny', \Spatie\Activitylog\Models\Activity::class)
        <x-audit.activity-history :activities="$this->activityHistory" />
    @endcan
</div>
