<?php

use App\Actions\GetAdministrativeDashboardMetrics;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Painel')] class extends Component
{
    /** @return array<string, mixed> */
    #[Computed]
    public function metrics(): array
    {
        Gate::authorize('viewAny', User::class);

        return app(GetAdministrativeDashboardMetrics::class)();
    }
}; ?>

@can('viewAny', \App\Models\User::class)
    @include('pages.dashboard.partials.system-administrator', ['metrics' => $this->metrics])
@else
    @include('pages.dashboard.partials.default')
@endcan
