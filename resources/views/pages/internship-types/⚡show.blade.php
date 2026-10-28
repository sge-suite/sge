<?php

use App\Actions\ManageInternshipType;
use App\Models\InternshipType;
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

new #[Title('Detalhes do tipo de estágio')] class extends Component
{
    use WithPagination;

    #[Locked]
    public int $internshipTypeId;

    public string $currentPassword = '';

    public string $deletionPassword = '';

    public bool $showDeactivation = false;

    public bool $showReactivation = false;

    public bool $showDeletion = false;

    public function boot(): void
    {
        Gate::authorize('viewAny', InternshipType::class);
        if (isset($this->internshipTypeId)) {
            Gate::authorize('view', InternshipType::findOrFail($this->internshipTypeId));
        }
    }

    public function mount(InternshipType $internshipType): void
    {
        Gate::authorize('view', $internshipType);
        $this->internshipTypeId = $internshipType->id;
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
        $this->clearCurrentPassword();
    }

    public function updatedShowDeletion(bool $show): void
    {
        if (! $show) {
            $this->clearDeletionPassword();
        }
    }

    public function closeDeletionModal(): void
    {
        $this->showDeletion = false;
        $this->clearDeletionPassword();
    }

    private function clearCurrentPassword(): void
    {
        $this->reset('currentPassword');
        $this->resetValidation('currentPassword');
        $this->resetValidation('current_password');
    }

    private function clearDeletionPassword(): void
    {
        $this->reset('deletionPassword');
        $this->resetValidation('deletionPassword');
        $this->resetValidation('current_password');
    }

    public function deactivateInternshipType(ManageInternshipType $manageInternshipType): void
    {
        Gate::authorize('deactivate', $this->internshipType);
        $this->validate([
            'currentPassword' => ['required', 'string', 'current_password'],
        ], [
            'currentPassword.required' => 'Informe sua senha atual.',
            'currentPassword.current_password' => 'A senha informada está incorreta.',
        ]);

        $manageInternshipType->handle(auth()->user(), $this->internshipType, 'deactivate', ['current_password' => $this->currentPassword]);
        unset($this->internshipType);
        $this->closeDeactivationModal();
        Flux::toast(variant: 'success', text: 'Tipo de estágio desativado com sucesso.');
    }

    public function reactivateInternshipType(ManageInternshipType $manageInternshipType): void
    {
        Gate::authorize('reactivate', $this->internshipType);
        $manageInternshipType->handle(auth()->user(), $this->internshipType, 'reactivate');
        unset($this->internshipType);
        $this->showReactivation = false;
        Flux::toast(variant: 'success', text: 'Tipo de estágio reativado com sucesso.');
    }

    public function deleteInternshipType(ManageInternshipType $manageInternshipType): void
    {
        Gate::authorize('delete', $this->internshipType);
        $this->validate([
            'deletionPassword' => ['required', 'string', 'current_password'],
        ], [
            'deletionPassword.required' => 'Informe sua senha atual.',
            'deletionPassword.current_password' => 'A senha informada está incorreta.',
        ]);

        $manageInternshipType->handle(auth()->user(), $this->internshipType, 'delete', ['current_password' => $this->deletionPassword]);
        $this->redirectRoute('internship-types.index', navigate: true);
    }

    #[Computed]
    public function internshipType(): InternshipType
    {
        $internshipType = InternshipType::with('course.campus')->findOrFail($this->internshipTypeId);
        Gate::authorize('view', $internshipType);

        return $internshipType;
    }

    /** @return LengthAwarePaginator<int, array{id: int, actor: string, subject: string, event: string, occurred_at: string}>|null */
    #[Computed]
    public function activityHistory(): ?LengthAwarePaginator
    {
        $query = app(ActivityAccess::class)->forCurrentContext(auth()->user(), app('session.store'));

        return $query === null ? null : app(ActivityHistory::class)->forSubject($this->internshipType, $query);
    }
}; ?>

<div class="w-full space-y-8">
    @php($internshipType = $this->internshipType)
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('internship-types.index')" wire:navigate>Tipos de estágio</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $internshipType->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1" class="wrap-break-word">{{ $internshipType->name }}</flux:heading>
                <flux:badge size="sm" :color="$internshipType->deactivated_at === null ? 'green' : 'zinc'">{{ $internshipType->deactivated_at === null ? 'Ativo' : 'Desativado' }}</flux:badge>
            </div>
            <flux:text class="mt-2">{{ $internshipType->course->name }} · {{ $internshipType->course->campus->name }}</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <flux:button variant="outline" icon="arrow-left" :href="route('internship-types.index')" wire:navigate>Voltar</flux:button>
            @can('update', $internshipType)
                <flux:button icon="pencil-square" :href="route('internship-types.edit', $internshipType)" wire:navigate>Editar tipo de estágio</flux:button>
            @endcan
        </div>
    </div>
    @if (session('status'))
        <div hidden x-data x-init="$nextTick(() => $flux.toast(@js(session('status')), { variant: 'success' }))"></div>
    @endif
    @if ($internshipType->course->campus->deactivated_at !== null)
        <flux:callout icon="information-circle" heading="Campus somente para leitura">
            <flux:callout.text>O campus está desativado. Os tipos de estágio podem ser consultados, mas não podem ser alterados.</flux:callout.text>
        </flux:callout>
    @endif
    @if ($internshipType->course->deactivated_at !== null)
        <flux:callout icon="information-circle" heading="Curso desativado">
            <flux:callout.text>Você pode manter o curso atual na edição. Novos cadastros, troca de curso e reativação exigem curso ativo.</flux:callout.text>
        </flux:callout>
    @endif
    @if ($internshipType->deactivated_at !== null)
        <flux:callout icon="information-circle" heading="Tipo de estágio desativado">
            <flux:callout.text>Desativado em {{ formatDateTime($internshipType->deactivated_at) }}. Os estágios e seus snapshots foram preservados.</flux:callout.text>
        </flux:callout>
    @endif
    <section class="border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="type-rules-heading">
        <flux:heading id="type-rules-heading" size="lg" level="2">Regras do tipo de estágio</flux:heading>
        <dl class="mt-5 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                'Anos ou semestres permitidos' => implode(', ', $internshipType->allowed_academic_periods ?? []),
                'Carga horária obrigatória' => $internshipType->required_hours.' horas',
                'Peso da avaliação do supervisor' => $internshipType->supervisor_evaluation_weight,
                'Peso do relatório' => $internshipType->report_weight,
                'Peso da apresentação' => $internshipType->presentation_weight,
                'Limite diário' => $internshipType->max_daily_hours.' horas',
                'Limite semanal' => $internshipType->max_weekly_hours.' horas',
                'Margem de segurança' => $internshipType->safety_margin_days.' dias',
            ] as $label => $value)
                <div wire:key="rule-{{ $loop->index }}">
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
    <section class="border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="type-concepts-heading">
        <flux:heading id="type-concepts-heading" size="lg" level="2">Conceitos da avaliação</flux:heading>
        <dl class="mt-5 grid gap-6 sm:grid-cols-2 lg:grid-cols-5">
            @foreach (\App\Enums\EvaluationConcept::cases() as $concept)
                <div wire:key="concept-{{ $concept->value }}">
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $concept->label() }}</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $concept->internshipTypeValueAttribute() === null ? $internshipType->supervisor_evaluation_weight : $internshipType->{$concept->internshipTypeValueAttribute()} }}</dd>
                </div>
            @endforeach
        </dl>
        <flux:text class="mt-5">Ótimo corresponde ao peso da avaliação do supervisor. Alterações neste cadastro preservam as regras congeladas nos estágios formalizados.</flux:text>
    </section>
    @if (auth()->user()->can('deactivate', $internshipType) || auth()->user()->can('reactivate', $internshipType))
        <section class="flex flex-col justify-between gap-5 rounded-xl border border-zinc-200 p-5 sm:flex-row sm:items-center dark:border-zinc-700" aria-labelledby="internship-type-availability-heading">
            <div>
                <flux:heading id="internship-type-availability-heading" level="2">Disponibilidade do tipo de estágio</flux:heading>
                <flux:text class="mt-1 max-w-xl">{{ $internshipType->deactivated_at === null ? 'A desativação impede novos usos do tipo de estágio e preserva os estágios existentes.' : 'A reativação permite novos usos do tipo de estágio. Os estágios e snapshots anteriores são preservados.' }}</flux:text>
            </div>
            @can('deactivate', $internshipType)
                <flux:button variant="danger" icon="no-symbol" x-on:click="$wire.showDeactivation = true">Desativar tipo de estágio</flux:button>
            @endcan
            @can('reactivate', $internshipType)
                <flux:button variant="primary" x-on:click="$wire.showReactivation = true">Reativar tipo de estágio</flux:button>
            @endcan
        </section>
    @endif
    @can('delete', $internshipType)
        <section class="flex flex-col justify-between gap-5 rounded-xl border border-red-200 p-5 sm:flex-row sm:items-center dark:border-red-900" aria-labelledby="internship-type-deletion-heading">
            <div>
                <flux:heading id="internship-type-deletion-heading" level="2">Excluir tipo de estágio</flux:heading>
                <flux:text class="mt-1 max-w-xl">A exclusão só fica disponível enquanto não houver solicitações ou estágios vinculados.</flux:text>
            </div>
            <flux:button variant="danger" icon="trash" x-on:click="$wire.showDeletion = true">Excluir tipo de estágio</flux:button>
        </section>
        <flux:modal wire:model="showDeletion" class="md:w-96">
            <div class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg">Excluir tipo de estágio</flux:heading>
                    <flux:text class="mt-2">Deseja excluir {{ $internshipType->name }}? Esta ação não pode ser desfeita.</flux:text>
                </div>
                <flux:error name="internship_type" />
                <flux:input wire:model="deletionPassword" label="Sua senha atual" type="password" autocomplete="current-password" required viewable />
                @if ($errors->has('current_password') && ! $errors->has('deletionPassword'))
                    <flux:error name="current_password" />
                @endif
                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="outline" wire:click="closeDeletionModal">Cancelar</flux:button>
                    <flux:button variant="danger" icon="trash" wire:click="deleteInternshipType">Confirmar exclusão</flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan
    @can('deactivate', $internshipType)
    <flux:modal name="deactivate-internship-type" wire:model.self="showDeactivation" class="md:w-md">
        <form wire:submit="deactivateInternshipType" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg" level="2">Desativar tipo de estágio</flux:heading>
                <flux:text class="mt-2">Confirme sua senha para desativar {{ $internshipType->name }}. Novos usos serão impedidos e os estágios existentes serão preservados.</flux:text>
            </div>
            <flux:input wire:model="currentPassword" label="Sua senha atual" type="password" autocomplete="current-password" required viewable />
            @if ($errors->has('current_password') && ! $errors->has('currentPassword'))
                <flux:error name="current_password" />
            @endif
            <div class="flex flex-wrap justify-end gap-3">
                <flux:modal.close><flux:button type="button" variant="outline" wire:click="closeDeactivationModal">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" icon="no-symbol" wire:loading.attr="disabled" wire:target="deactivateInternshipType">Desativar tipo de estágio</flux:button>
            </div>
        </form>
    </flux:modal>
    @endcan
    @can('reactivate', $internshipType)
    <flux:modal wire:model="showReactivation" class="md:w-96">
        <div class="flex flex-col gap-6">
            <div><flux:heading size="lg">Reativar tipo de estágio</flux:heading><flux:text class="mt-2">Deseja reativar {{ $internshipType->name }} para permitir novos usos? O curso precisa estar ativo.</flux:text></div>
            <flux:error name="course_id" />
            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showReactivation', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="reactivateInternshipType">Confirmar reativação</flux:button>
            </div>
        </div>
    </flux:modal>
    @endcan
    @can('viewAny', \Spatie\Activitylog\Models\Activity::class)
        <x-audit.activity-history :activities="$this->activityHistory" />
    @endcan
</div>
