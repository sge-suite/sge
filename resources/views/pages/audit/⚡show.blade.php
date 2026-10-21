<?php

use App\Models\Affiliation;
use App\Support\AdministrativeActivityPresenter;
use App\Support\ActivityNavigation;
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

    #[Locked]
    public string $returnTo;

    public function boot(): void
    {
        Gate::authorize('viewAny', Activity::class);
    }

    public function mount(Activity $activity, ActivityNavigation $navigation): void
    {
        $this->activityId = $activity->id;
        $this->returnTo = $navigation->backUrl(request()->query('return_to', url()->previous()));
    }

    /** @return array{actor: string, subject: string, event: string, occurred_at: string, is_creation: bool, is_download: bool, subject_url: ?string, actor_url: ?string, details: array<string, array{value: string, url: ?string}>, changes: list<array{field: string, before: string, after: string, before_url?: ?string, after_url?: ?string}>} */
    #[Computed]
    public function entry(): array
    {
        $activity = Activity::findOrFail($this->activityId);
        Gate::authorize('view', $activity);
        $activity->load([
            'subject' => fn (MorphTo $relation) => $relation
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
        <flux:button variant="outline" icon="arrow-left" :href="$returnTo" wire:navigate>Voltar</flux:button>
    </div>

    <section class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700" aria-labelledby="audit-event-heading">
        <flux:heading id="audit-event-heading" size="lg" level="2">Detalhes da ação</flux:heading>
        <dl class="mt-6 grid gap-5 sm:grid-cols-2">
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Registro afetado</dt><dd class="mt-1 font-medium">@if ($entry['subject_url'])<flux:link :href="$entry['subject_url']" wire:navigate>{{ $entry['subject'] }}</flux:link>@else{{ $entry['subject'] }}@endif</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Ação</dt><dd class="mt-1"><flux:badge color="zinc">{{ $entry['event'] }}</flux:badge></dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Realizada por</dt><dd class="mt-1 font-medium">@if ($entry['actor_url'])<flux:link :href="$entry['actor_url']" wire:navigate>{{ $entry['actor'] }}</flux:link>@else{{ $entry['actor'] }}@endif</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Quando</dt><dd class="mt-1 font-medium">{{ $entry['occurred_at'] }}</dd></div>
        </dl>
        <flux:text class="mt-6">Os IDs identificam os registros do histórico. Nomes e funções atuais podem ter mudado desde a ação.</flux:text>
    </section>

    @if ($entry['details'] !== [])
        <section class="space-y-4" aria-labelledby="audit-download-heading">
            <flux:heading id="audit-download-heading" size="lg" level="2">{{ $entry['is_download'] ? 'Detalhes do download' : 'Contexto do evento' }}</flux:heading>
            <dl class="grid gap-5 sm:grid-cols-2">
                @foreach ($entry['details'] as $label => $detail)
                    <div wire:key="event-detail-{{ $loop->index }}"><dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="mt-1 wrap-break-word font-medium">@if ($detail['url'])<flux:link :href="$detail['url']" wire:navigate>{{ $detail['value'] }}</flux:link>@else{{ $detail['value'] }}@endif</dd></div>
                @endforeach
            </dl>
            @if ($entry['is_download'])<flux:text>O envio para download registra a transmissão pelo servidor. Não confirma que o arquivo foi salvo ou aberto no dispositivo.</flux:text>@endif
        </section>
    @endif
    @if ($entry['changes'] !== [] || $entry['details'] === [])
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
                                <flux:table.cell class="whitespace-normal wrap-break-word">@if ($change['before_url'] ?? null)<flux:link :href="$change['before_url']" wire:navigate>{{ $change['before'] }}</flux:link>@else<span class="text-red-700 dark:text-red-400">{{ $change['before'] }}</span>@endif</flux:table.cell>
                            @endif
                            <flux:table.cell class="whitespace-normal wrap-break-word">@if ($change['after_url'] ?? null)<flux:link :href="$change['after_url']" wire:navigate>{{ $change['after'] }}</flux:link>@else<span class="text-green-700 dark:text-green-400">{{ $change['after'] }}</span>@endif</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <flux:text>Nenhum valor de campo disponível para exibição neste evento.</flux:text>
        @endif
    </section>
    @endif
</div>
