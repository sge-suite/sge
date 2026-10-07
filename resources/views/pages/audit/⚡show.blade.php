<?php

use App\Models\Affiliation;
use App\Models\Campus;
use App\Support\AdministrativeActivityPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

new #[Title('Detalhes da auditoria')] class extends Component
{
    #[Locked]
    public int $activityId;

    public function boot(): void
    {
        Gate::authorize('viewAny', Activity::class);
    }

    public function mount(Activity $activity): void
    {
        $this->activityId = $activity->id;
    }

    /** @return array{actor: string, subject: string, event: string, occurred_at: string, changes: list<array{field: string, before: string, after: string}>} */
    #[Computed]
    public function entry(): array
    {
        $activity = Activity::findOrFail($this->activityId);
        Gate::authorize('view', $activity);
        $activity->load([
            'subject' => fn (MorphTo $relation) => $relation
                ->constrain([Campus::class => fn (Builder $query) => $query->withTrashed()])
                ->morphWith([Affiliation::class => ['user']]),
            'causer' => fn (MorphTo $relation) => $relation->morphWith([Affiliation::class => ['user']]),
        ]);

        return app(AdministrativeActivityPresenter::class)->present($activity);
    }
}; ?>

<div class="w-full space-y-8">
    @php($entry = $this->entry)
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('audit.index')" wire:navigate>Auditoria</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Evento #{{ $activityId }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">Evento #{{ $activityId }}</flux:heading>
        <flux:button variant="outline" icon="arrow-left" :href="route('audit.index')" wire:navigate>Voltar</flux:button>
    </div>

    <section class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700" aria-labelledby="audit-event-heading">
        <flux:heading id="audit-event-heading" size="lg" level="2">Detalhes da ação</flux:heading>
        <dl class="mt-6 grid gap-5 sm:grid-cols-2">
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Registro afetado</dt><dd class="mt-1 font-medium">{{ $entry['subject'] }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Ação</dt><dd class="mt-1"><flux:badge color="zinc">{{ $entry['event'] }}</flux:badge></dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Realizada por</dt><dd class="mt-1 font-medium">{{ $entry['actor'] }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Quando</dt><dd class="mt-1 font-medium">{{ $entry['occurred_at'] }}</dd></div>
        </dl>
        <flux:text class="mt-6">Os IDs identificam os registros do histórico. Nomes e funções atuais podem ter mudado desde a ação.</flux:text>
    </section>

    <section class="space-y-4" aria-labelledby="audit-changes-heading">
        <div>
            <flux:heading id="audit-changes-heading" size="lg" level="2">Campos alterados</flux:heading>
            <flux:text>“Não registrado” indica que o log não contém aquele valor; “Sem valor” indica um valor nulo registrado.</flux:text>
        </div>
        @if ($entry['changes'] !== [])
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Campo</flux:table.column>
                    @if (! $entry['is_creation'])
                        <flux:table.column>Valor anterior</flux:table.column>
                    @endif
                    <flux:table.column>Valor novo</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($entry['changes'] as $change)
                        <flux:table.row wire:key="activity-{{ $activityId }}-{{ $loop->index }}">
                            <flux:table.cell>{{ $change['field'] }}</flux:table.cell>
                            @if (! $entry['is_creation'])
                                <flux:table.cell class="whitespace-normal break-words"><span class="text-red-700 dark:text-red-400">{{ $change['before'] }}</span></flux:table.cell>
                            @endif
                            <flux:table.cell class="whitespace-normal break-words"><span class="text-green-700 dark:text-green-400">{{ $change['after'] }}</span></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <flux:text>Nenhum valor de campo disponível para exibição neste evento.</flux:text>
        @endif
    </section>
</div>
