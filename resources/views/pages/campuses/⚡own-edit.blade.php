<?php

use App\Models\Campus;
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

new #[Title('Editar meu campus')] class extends Component
{
    use WithPagination;

    #[Locked]
    public int $campusId;

    public function boot(): void
    {
        Gate::authorize('viewOwn', Campus::class);
        if (isset($this->campusId)) {
            Gate::authorize('update', Campus::with('address.city')->findOrFail($this->campusId));
        }
    }

    public function mount(ActiveAffiliationContext $context): void
    {
        $affiliation = $context->currentFor(auth()->user(), app('session.store'));
        abort_if($affiliation?->campus_id === null, 403);
        $this->campusId = $affiliation->campus_id;
        Gate::authorize('update', $this->campus);
    }

    #[Computed]
    public function campus(): Campus
    {
        $campus = Campus::with('address.city')->findOrFail($this->campusId);
        Gate::authorize('update', $campus);

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
        <flux:breadcrumbs.item :href="route('campuses.own')" wire:navigate>Meu campus</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Editar</flux:breadcrumbs.item>
    </flux:breadcrumbs>
        <form method="POST" action="{{ route('campuses.update', $campus) }}" class="space-y-8" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false">
            @csrf
            @method('PUT')
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <flux:heading size="xl" level="1">Editar meu campus</flux:heading>
                    <flux:text class="mt-2">Atualize os dados de {{ $campus->name }}.</flux:text>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-3">
                    <flux:button variant="outline" :href="route('campuses.own')" wire:navigate>Cancelar</flux:button>
                    <flux:button variant="primary" type="submit" :loading="false" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                        <flux:icon.loading x-show="submitting" x-cloak class="size-4" />
                        Salvar alterações
                    </flux:button>
                </div>
            </div>
            <livewire:campuses.form-fields :campus="$campus" :key="'own-campus-fields-'.$campus->id" />
        </form>
</div>
