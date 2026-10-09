<?php

use App\Actions\ManageCourse;
use App\Models\Course;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Detalhes do curso')] class extends Component
{
    #[Locked]
    public int $courseId;

    public string $currentPassword = '';

    public bool $showDeactivation = false;

    public bool $showReactivation = false;

    public bool $showDeletion = false;

    public string $deletionPassword = '';

    public function boot(): void
    {
        Gate::authorize('viewAny', Course::class);
        if (isset($this->courseId)) {
            Gate::authorize('view', Course::findOrFail($this->courseId));
        }
    }

    public function mount(Course $course): void
    {
        Gate::authorize('view', $course);
        $this->courseId = $course->id;
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

    public function deactivateCourse(ManageCourse $manageCourse): void
    {
        Gate::authorize('deactivate', $this->course);
        $this->validate([
            'currentPassword' => ['required', 'string', 'current_password'],
        ], [
            'currentPassword.required' => 'Informe sua senha atual.',
            'currentPassword.current_password' => 'A senha informada está incorreta.',
        ]);

        $manageCourse->handle(auth()->user(), $this->course, 'deactivate', ['current_password' => $this->currentPassword]);
        unset($this->course);
        $this->closeDeactivationModal();
        Flux::toast(variant: 'success', text: 'Curso desativado com sucesso.');
    }

    public function reactivateCourse(ManageCourse $manageCourse): void
    {
        Gate::authorize('reactivate', $this->course);
        $manageCourse->handle(auth()->user(), $this->course, 'reactivate');
        unset($this->course);
        $this->showReactivation = false;
        Flux::toast(variant: 'success', text: 'Curso reativado com sucesso.');
    }

    public function closeDeletionModal(): void
    {
        $this->showDeletion = false;
        $this->reset('deletionPassword');
        $this->resetValidation('deletionPassword');
        $this->resetValidation('deletion');
    }

    public function deleteCourse(ManageCourse $manageCourse): void
    {
        Gate::authorize('delete', $this->course);
        $this->resetValidation('deletion');
        $this->validate([
            'deletionPassword' => ['required', 'string', 'current_password'],
        ], [
            'deletionPassword.required' => 'Informe sua senha atual.',
            'deletionPassword.current_password' => 'A senha informada está incorreta.',
        ]);

        $manageCourse->handle(auth()->user(), $this->course, 'delete', ['current_password' => $this->deletionPassword]);
        session()->flash('status', 'Curso apagado com sucesso.');
        $this->redirectRoute('courses.index', navigate: true);
    }

    #[Computed]
    public function course(): Course
    {
        $course = Course::with(['campus', 'primaryCoordinator.user', 'secondaryCoordinator.user'])->findOrFail($this->courseId);
        Gate::authorize('view', $course);

        return $course;
    }
}; ?>

<div class="w-full space-y-8">
    @php($course = $this->course)
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('courses.index')" wire:navigate>Cursos</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $course->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1" class="break-words">{{ $course->name }}</flux:heading>
                <flux:badge size="sm" :color="$course->deactivated_at === null ? 'green' : 'zinc'">{{ $course->deactivated_at === null ? 'Ativo' : 'Desativado' }}</flux:badge>
            </div>
            <flux:text class="mt-2">{{ $course->campus->name }}</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <flux:button variant="outline" icon="arrow-left" :href="route('courses.index')" wire:navigate>Voltar</flux:button>
            @can('update', $course)
                <flux:button icon="pencil-square" :href="route('courses.edit', $course)" wire:navigate>Editar curso</flux:button>
            @endcan
        </div>
    </div>
    @if (session('status'))
        <div x-data x-init="$nextTick(() => $flux.toast(@js(session('status')), { variant: 'success' }))"></div>
    @endif
    @if ($course->campus->deactivated_at !== null)
        <flux:callout icon="information-circle" heading="Campus somente para leitura">
            <flux:callout.text>O campus está desativado. Os cursos podem ser consultados, mas não podem ser alterados.</flux:callout.text>
        </flux:callout>
    @endif
    @if ($course->deactivated_at !== null)
        <flux:callout icon="information-circle" heading="Curso desativado">
            <flux:callout.text>Desativado em {{ formatDateTime($course->deactivated_at) }}. Os vínculos e registros históricos foram preservados.</flux:callout.text>
        </flux:callout>
    @endif
    <section class="border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="course-coordinators-heading">
        <flux:heading id="course-coordinators-heading" size="lg" level="2">Coordenação</flux:heading>
        <dl class="mt-5 grid gap-6 md:grid-cols-2">
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Coordenador principal</dt>
                <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $course->primaryCoordinator?->user?->name ?? 'Não informado' }}</dd>
                @if ($course->primaryCoordinator?->deactivated_at !== null)
                    <flux:text class="mt-1">Vínculo desativado · referência histórica</flux:text>
                @endif
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Coordenador secundário</dt>
                <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $course->secondaryCoordinator?->user?->name ?? 'Não informado' }}</dd>
                @if ($course->secondaryCoordinator?->deactivated_at !== null)
                    <flux:text class="mt-1">Vínculo desativado · referência histórica</flux:text>
                @endif
            </div>
        </dl>
    </section>
    @if (auth()->user()->can('deactivate', $course) || auth()->user()->can('reactivate', $course))
        <section class="flex flex-col justify-between gap-5 rounded-xl border border-zinc-200 p-5 sm:flex-row sm:items-center dark:border-zinc-700" aria-labelledby="course-availability-heading">
            <div>
                <flux:heading id="course-availability-heading" level="2">Disponibilidade do curso</flux:heading>
                <flux:text class="mt-1 max-w-xl">{{ $course->deactivated_at === null ? 'A desativação impede novas atribuições do curso e preserva os vínculos existentes.' : 'A reativação permite novas atribuições do curso. Os vínculos e registros anteriores são preservados.' }}</flux:text>
            </div>
            @can('deactivate', $course)
                <flux:button variant="danger" icon="no-symbol" x-on:click="$wire.showDeactivation = true">Desativar curso</flux:button>
            @endcan
            @can('reactivate', $course)
                <flux:button variant="primary" x-on:click="$wire.showReactivation = true">Reativar curso</flux:button>
            @endcan
        </section>
    @endif
    @can('delete', $course)
        @if (! $course->hasLinkedRecords())
            <section class="flex flex-col justify-between gap-5 rounded-xl border border-red-200 p-5 sm:flex-row sm:items-center dark:border-red-900" aria-labelledby="course-deletion-heading">
                <div>
                    <flux:heading id="course-deletion-heading" level="2">Apagar curso</flux:heading>
                    <flux:text class="mt-1 max-w-xl">Apaga permanentemente o curso. A opção fica disponível apenas quando não há vínculos ou registros associados.</flux:text>
                </div>
                <flux:button data-course-delete-action variant="danger" icon="trash" wire:click="$set('showDeletion', true)">Apagar curso</flux:button>
            </section>
        @endif
        <flux:modal name="delete-course" wire:model.self="showDeletion" class="md:w-md">
            <form wire:submit="deleteCourse" class="space-y-6">
                <div>
                    <flux:heading size="lg" level="2">Apagar curso</flux:heading>
                    <flux:text class="mt-2">Esta ação é permanente e apagará {{ $course->name }} somente se não houver vínculos ou registros associados. Confirme sua senha para continuar.</flux:text>
                </div>
                <flux:input wire:model="deletionPassword" label="Sua senha atual" type="password" autocomplete="current-password" required viewable />
                <flux:error name="deletion" />
                <div class="flex flex-wrap justify-end gap-3">
                    <flux:modal.close><flux:button type="button" variant="outline" wire:click="closeDeletionModal">Cancelar</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="deleteCourse">Apagar curso</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
    @can('deactivate', $course)
    <flux:modal name="deactivate-course" wire:model.self="showDeactivation" class="md:w-md">
        <form wire:submit="deactivateCourse" class="space-y-6">
            <div>
                <flux:heading size="lg" level="2">Desativar curso</flux:heading>
                <flux:text class="mt-2">Confirme sua senha para desativar {{ $course->name }}. Novas atribuições serão impedidas e os vínculos existentes serão preservados.</flux:text>
            </div>
            <flux:input wire:model="currentPassword" label="Sua senha atual" type="password" autocomplete="current-password" required viewable />
            @if ($errors->has('current_password') && ! $errors->has('currentPassword'))
                <flux:error name="current_password" />
            @endif
            <div class="flex flex-wrap justify-end gap-3">
                <flux:modal.close><flux:button type="button" variant="outline" wire:click="closeDeactivationModal">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" icon="no-symbol" wire:loading.attr="disabled" wire:target="deactivateCourse">Desativar curso</flux:button>
            </div>
        </form>
    </flux:modal>
    @endcan
    @can('reactivate', $course)
    <flux:modal wire:model="showReactivation" class="md:w-96">
        <div class="space-y-6">
            <div><flux:heading size="lg">Reativar curso</flux:heading><flux:text class="mt-2">Deseja reativar {{ $course->name }} para permitir novas atribuições?</flux:text></div>
            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showReactivation', false)">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="reactivateCourse">Confirmar reativação</flux:button>
            </div>
        </div>
    </flux:modal>
    @endcan
</div>
