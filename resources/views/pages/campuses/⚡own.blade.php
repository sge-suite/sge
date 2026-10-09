<?php

use App\Models\Campus;
use App\Support\ActiveAffiliationContext;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Meu campus')] class extends Component
{
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
}; ?>

<div class="w-full space-y-8">
    @php($campus = $this->campus)
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>Painel</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Meu campus</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    @if (session('status'))
        <div x-data x-init="$nextTick(() => $flux.toast(@js(session('status')), { variant: 'success' }))"></div>
    @endif
    @can('update', $campus)
        <form method="POST" action="{{ route('campuses.update', $campus) }}" class="space-y-8" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false">
            @csrf
            @method('PUT')
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <flux:heading size="xl" level="1">Meu campus</flux:heading>
                    <flux:text class="mt-2">Atualize os dados de {{ $campus->name }}.</flux:text>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-3">
                    <flux:button variant="outline" :href="route('dashboard')" wire:navigate>Cancelar</flux:button>
                    <flux:button variant="primary" type="submit" :loading="false" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                        <flux:icon.loading x-show="submitting" x-cloak class="size-4" />
                        Salvar alterações
                    </flux:button>
                </div>
            </div>
            <livewire:campuses.form-fields :campus="$campus" :key="'own-campus-fields-'.$campus->id" />
        </form>
    @else
        <div>
            <flux:heading size="xl" level="1">Meu campus</flux:heading>
            <flux:text class="mt-2">{{ $campus->name }}</flux:text>
        </div>
        <flux:callout icon="information-circle" heading="Campus somente para leitura">
            <flux:callout.text>O campus está desativado. Seus dados podem ser consultados, mas não podem ser alterados.</flux:callout.text>
        </flux:callout>
        <dl class="grid gap-6 sm:grid-cols-2">
            @foreach (['phone' => 'Telefone', 'legal_representative_name' => 'Representante legal', 'legal_representative_position' => 'Cargo', 'insurance_company_name' => 'Seguradora', 'insurance_policy_number' => 'Número da apólice'] as $field => $label)
                <div wire:key="campus-detail-{{ $field }}">
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $field === 'phone' ? \App\Helpers\BrazilianContactHelper::formatPhone($campus->phone) : $campus->{$field} }}</dd>
                </div>
            @endforeach
        </dl>
    @endcan
</div>
