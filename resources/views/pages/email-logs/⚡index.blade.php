<?php

use App\Enums\EmailDeliveryAttemptStatus;
use App\Enums\EmailMessagePurpose;
use App\Models\EmailDeliveryAttempt;
use App\Support\EmailLogAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('E-mails')] class extends Component
{
    use WithPagination;

    #[Url(except: 'all')]
    public string $purpose = 'all';

    #[Url(except: 'all')]
    public string $status = 'all';

    public function boot(): void
    {
        Gate::authorize('viewAny', EmailDeliveryAttempt::class);
    }

    public function mount(): void
    {
        $this->normalizeFilters();
    }

    public function updatedPurpose(): void
    {
        $this->normalizeFilters();
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->normalizeFilters();
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->purpose = 'all';
        $this->status = 'all';
        $this->resetPage();
    }

    private function normalizeFilters(): void
    {
        if (! array_key_exists($this->purpose, $this->purposeOptions)) {
            $this->purpose = 'all';
        }
        if (EmailDeliveryAttemptStatus::tryFrom($this->status) === null) {
            $this->status = 'all';
        }
    }

    /** @return array<string, string> */
    #[Computed]
    public function purposeOptions(): array
    {
        return app(EmailLogAccess::class)->purposeOptionsForCurrentContext(auth()->user(), app('session.store'));
    }

    #[Computed]
    public function deliveries(): LengthAwarePaginator
    {
        $query = app(EmailLogAccess::class)->forCurrentContext(auth()->user(), app('session.store'));
        abort_if($query === null, 403);

        return $query->whereIn('id', EmailDeliveryAttempt::query()->selectRaw('MAX(id)')->groupBy('delivery_key'))
            ->when(EmailMessagePurpose::tryFrom($this->purpose), fn (Builder $query, EmailMessagePurpose $purpose): Builder => $query->where('purpose', $purpose))
            ->when(EmailDeliveryAttemptStatus::tryFrom($this->status), fn (Builder $query, EmailDeliveryAttemptStatus $status): Builder => $query->where('status', $status))
            ->with('emailMessage:id,subject')
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(15);
    }
}; ?>

<div class="w-full space-y-8">
    <div>
        <flux:heading size="xl" level="1">E-mails</flux:heading>
        <flux:text>Consulte destinatários, conteúdo registrado e tentativas de envio dentro do seu escopo.</flux:text>
    </div>
    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full sm:max-w-xs">
            <x-select wire:model.live="purpose" label="Finalidade" :value="$purpose" :options="['all' => 'Todas as finalidades', ...$this->purposeOptions]" />
        </div>
        <div class="w-full sm:max-w-xs">
            <x-select wire:model.live="status" label="Situação" :value="$status" :options="['all' => 'Todas as situações', ...EmailDeliveryAttemptStatus::options()]" />
        </div>
        @if ($purpose !== 'all' || $status !== 'all')
            <flux:button wire:click="clearFilters">Limpar filtros</flux:button>
        @endif
    </div>
    <x-loading-overlay target="purpose,status,clearFilters,gotoPage,nextPage,previousPage" label="Atualizando histórico…">
        @php($deliveries = $this->deliveries)
        <x-email-logs.delivery-table :deliveries="$deliveries" />
        @if ($deliveries->isEmpty())
            <flux:text>Nenhum envio encontrado nesta página.</flux:text>
        @endif
    </x-loading-overlay>
</div>
