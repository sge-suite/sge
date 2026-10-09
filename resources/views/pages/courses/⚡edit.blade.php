<?php

use App\Models\Course;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Editar curso')] class extends Component
{
    #[Locked]
    public int $courseId;

    public function boot(): void
    {
        Gate::authorize('viewAny', Course::class);
        if (isset($this->courseId)) {
            Gate::authorize('update', Course::findOrFail($this->courseId));
        }
    }

    public function mount(Course $course): void
    {
        Gate::authorize('update', $course);
        $this->courseId = $course->id;
    }

    #[Computed]
    public function course(): Course
    {
        $course = Course::findOrFail($this->courseId);
        Gate::authorize('update', $course);

        return $course;
    }
}; ?>

<div class="w-full space-y-8">
    @php($course = $this->course)
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('courses.index')" wire:navigate>Cursos</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('courses.show', $course)" wire:navigate>{{ $course->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Editar</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <form method="POST" action="{{ route('courses.update', $course) }}" class="space-y-8" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false" novalidate>
        @csrf
        @method('PUT')
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">Editar curso</flux:heading>
                <flux:text class="mt-2">Atualize os dados de {{ $course->name }}.</flux:text>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <flux:button variant="outline" :href="route('courses.show', $course)" wire:navigate>Cancelar</flux:button>
                <flux:button variant="primary" type="submit" :loading="false" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                    <flux:icon.loading x-show="submitting" x-cloak class="size-4" />Salvar alterações
                </flux:button>
            </div>
        </div>
        <livewire:courses.form-fields :course="$course" />
    </form>
</div>
