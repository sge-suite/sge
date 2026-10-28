<?php

use App\Models\Course;
use App\Models\InternshipType;
use App\Support\ActiveAffiliationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use \App\Concerns\SearchesSelectOptions;

    protected function selectSearchSource(string $source): array
    {
        abort_unless($source === 'courses', 404);

        return ['model' => Course::class, 'options' => $this->courseOptions];
    }

    #[Locked]
    public ?int $internshipTypeId = null;

    #[Locked]
    public ?int $campusId = null;

    /** @var array<string, mixed> */
    public array $values = [];

    public string $periodNumber = '';

    public string $periodUnit = 'semester';

    public function boot(): void
    {
        Gate::authorize('viewAny', InternshipType::class);
        Gate::authorize($this->internshipTypeId === null ? 'create' : 'update', $this->internshipTypeId === null ? InternshipType::class : InternshipType::findOrFail($this->internshipTypeId));
        $affiliation = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'));
        abort_if($this->campusId !== null && $this->campusId !== $affiliation?->campus_id, 403);
    }

    public function mount(?InternshipType $internshipType = null): void
    {
        $this->internshipTypeId = $internshipType?->id;
        Gate::authorize($internshipType === null ? 'create' : 'update', $internshipType ?? InternshipType::class);
        $this->campusId = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'))->campus_id;
        foreach ([
            'course_id' => '', 'name' => '', 'required_hours' => '',
            'supervisor_evaluation_weight' => '', 'report_weight' => '', 'presentation_weight' => '',
            'very_good_value' => '', 'good_value' => '', 'satisfactory_value' => '', 'unsatisfactory_value' => 0,
            'max_daily_hours' => 6, 'max_weekly_hours' => 30, 'safety_margin_days' => 7,
        ] as $field => $default) {
            $value = old($field, $internshipType?->{$field} ?? $default);
            $this->values[$field] = is_scalar($value) ? $value : '';
        }
        $periods = old('allowed_academic_periods', $internshipType?->allowed_academic_periods ?? []);
        $this->values['allowed_academic_periods'] = is_array($periods) ? array_values($periods) : [];
    }

    public function addAcademicPeriod(): void
    {
        $this->validate(['periodNumber' => ['required', 'integer', 'min:1', 'max:99'],
            'periodUnit' => ['required', \Illuminate\Validation\Rule::in(['year', 'semester'])]],
            attributes: ['periodNumber' => 'número do ano ou semestre', 'periodUnit' => 'tipo de período']);
        $period = (int) $this->periodNumber.'º '.($this->periodUnit === 'year' ? 'ano' : 'semestre');
        $periods = $this->values['allowed_academic_periods'];
        if (in_array($period, $periods, true)) {
            $this->addError('periodNumber', 'Este período já foi adicionado.');

            return;
        }
        if (count($periods) >= 50) {
            $this->addError('allowed_academic_periods', 'Adicione no máximo 50 períodos.');

            return;
        }
        $this->values['allowed_academic_periods'][] = $period;
        $this->periodNumber = '';
        $this->resetValidation(['allowed_academic_periods', 'periodNumber']);
    }

    public function removeAcademicPeriod(int $index): void
    {
        abort_unless(array_key_exists($index, $this->values['allowed_academic_periods']), 422);
        array_splice($this->values['allowed_academic_periods'], $index, 1);
        $this->resetValidation('allowed_academic_periods');
    }

    /** @return array<int|string, string> */
    #[Computed]
    public function courseOptions(): array
    {
        $currentCourseId = null;
        if ($this->internshipTypeId !== null) {
            $internshipType = InternshipType::findOrFail($this->internshipTypeId);
            Gate::authorize('update', $internshipType);
            $currentCourseId = $internshipType->course_id;
        }

        return ['' => 'Selecione um curso'] + Course::query()->where('campus_id', $this->campusId)
            ->where(function (Builder $query) use ($currentCourseId): void {
                $query->whereNull('deactivated_at');
                if ($currentCourseId !== null) {
                    $query->orWhere('id', $currentCourseId);
                }
            })->orderBy('name')->orderBy('id')->get()
            ->mapWithKeys(fn (Course $course): array => [$course->id => $course->name.($course->deactivated_at !== null ? ' (curso desativado · associação atual)' : '')])->all();
    }
}; ?>

<div class="space-y-8">
    <section class="space-y-6 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="type-data-heading">
        <flux:heading id="type-data-heading" size="lg" level="2">Dados do tipo de estágio</flux:heading>
        <div class="grid items-start gap-6 md:grid-cols-2">
            <flux:input name="name" :value="$values['name']" wire:model="values.name" label="Nome" required maxlength="255" :invalid="$errors->has('name')" />
            <x-select search-source="courses" name="course_id" required wire:model="values.course_id" label="Curso" :value="$values['course_id']" :options="$this->courseOptions" :searchable="true" :invalid="$errors->has('course_id')" :error="$errors->first('course_id')" />
        </div>
        <flux:input name="required_hours" :value="$values['required_hours']" wire:model="values.required_hours" label="Carga horária obrigatória (horas)" type="number" min="1" step="1" required :invalid="$errors->has('required_hours')" />
        <flux:text>Cadastro e troca de curso exigem curso ativo. Na edição, a associação atual pode ser mantida mesmo se o curso estiver desativado.</flux:text>
        @if (count($this->courseOptions) === 1)
            <flux:callout icon="information-circle" heading="Nenhum curso elegível">
                <flux:callout.text>Cadastre ou reative um curso deste campus antes de cadastrar um tipo de estágio.</flux:callout.text>
            </flux:callout>
        @endif
    </section>
    <section class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="type-periods-heading">
        <flux:heading id="type-periods-heading" size="lg" level="2">Anos ou semestres permitidos</flux:heading>
        <flux:text>Adicione os períodos do curso em que o discente pode solicitar este estágio. Por exemplo: 3º ano ou 5º e 6º semestres. No formulário, ele escolherá uma das opções cadastradas aqui.</flux:text>
        <div class="grid items-start gap-5 sm:grid-cols-3">
            <x-select wire:model="periodUnit" :value="$periodUnit" label="Tipo de período" :options="['year' => 'Ano', 'semester' => 'Semestre']" :error="$errors->first('periodUnit')" />
            <flux:input name="" wire:model="periodNumber" label="Número do ano ou semestre" type="number" min="1" max="99" step="1" wire:keydown.enter.prevent="addAcademicPeriod" :error:message="$errors->first('periodNumber')" />
            <div class="self-end"><flux:button type="button" icon="plus" wire:click="addAcademicPeriod" wire:loading.attr="disabled">Adicionar período</flux:button></div>
        </div>
        <flux:error name="allowed_academic_periods" />
        <div class="flex flex-wrap gap-3">
            @forelse ($values['allowed_academic_periods'] as $index => $period)
                <div wire:key="academic-period-{{ $index }}-{{ $period }}" class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <input type="hidden" name="allowed_academic_periods[]" value="{{ $period }}" />
                    <span class="text-sm">{{ $period }}</span>
                    <flux:button type="button" size="xs" variant="ghost" icon="x-mark" :aria-label="'Remover '.$period" wire:click="removeAcademicPeriod({{ $index }})" />
                    <flux:error name="allowed_academic_periods.{{ $index }}" />
                </div>
            @empty
                <flux:text size="sm">Nenhum período adicionado. Adicione pelo menos uma opção antes de salvar.</flux:text>
            @endforelse
        </div>
    </section>
    <section class="space-y-6 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="type-weights-heading">
        <div>
            <flux:heading id="type-weights-heading" size="lg" level="2">Pesos da avaliação</flux:heading>
            <flux:text class="mt-2">Informe pesos inteiros positivos. A soma dos três pesos deve ser exatamente 10.</flux:text>
        </div>
        <div class="grid items-start gap-6 md:grid-cols-3">
            <flux:input name="supervisor_evaluation_weight" :value="$values['supervisor_evaluation_weight']" wire:model.live="values.supervisor_evaluation_weight" label="Avaliação do supervisor" type="number" min="1" step="1" required :invalid="$errors->has('supervisor_evaluation_weight')" />
            <flux:input name="report_weight" :value="$values['report_weight']" wire:model="values.report_weight" label="Relatório" type="number" min="1" step="1" required :invalid="$errors->has('report_weight')" />
            <flux:input name="presentation_weight" :value="$values['presentation_weight']" wire:model="values.presentation_weight" label="Apresentação" type="number" min="1" step="1" required :invalid="$errors->has('presentation_weight')" />
        </div>
    </section>
    <section class="space-y-6 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="type-concepts-heading">
        <div>
            <flux:heading id="type-concepts-heading" size="lg" level="2">Conceitos da avaliação</flux:heading>
            <flux:text class="mt-2">Valores não negativos, com até uma casa decimal, em ordem estritamente decrescente. Ótimo é o peso do supervisor.</flux:text>
        </div>
        <div class="grid items-start gap-6 sm:grid-cols-2 lg:grid-cols-5">
            @foreach (\App\Enums\EvaluationConcept::cases() as $concept)
                <div wire:key="concept-field-{{ $concept->value }}">
                    @if ($concept->internshipTypeValueAttribute() === null)
                        <flux:input :value="is_scalar($values['supervisor_evaluation_weight']) ? $values['supervisor_evaluation_weight'] : ''" :label="$concept->label()" readonly />
                    @else
                        @php($field = $concept->internshipTypeValueAttribute())
                        <flux:input :name="$field" :value="$values[$field]" wire:model="values.{{ $field }}" :label="$concept->label()" type="text" inputmode="decimal" data-decimal-input required :invalid="$errors->has($field)" />
                    @endif
                </div>
            @endforeach
        </div>
    </section>
    <section class="space-y-6 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="type-schedule-heading">
        <flux:heading id="type-schedule-heading" size="lg" level="2">Jornada e previsão de término</flux:heading>
        <div class="grid items-start gap-6 md:grid-cols-3">
            <flux:input name="max_daily_hours" :value="$values['max_daily_hours']" wire:model="values.max_daily_hours" label="Limite diário (horas)" type="number" min="6" max="24" step="1" required :invalid="$errors->has('max_daily_hours')" />
            <flux:input name="max_weekly_hours" :value="$values['max_weekly_hours']" wire:model="values.max_weekly_hours" label="Limite semanal (horas)" type="number" min="30" max="40" step="1" required :invalid="$errors->has('max_weekly_hours')" />
            <flux:input name="safety_margin_days" :value="$values['safety_margin_days']" wire:model="values.safety_margin_days" label="Margem de segurança (dias)" type="number" min="0" step="1" required :invalid="$errors->has('safety_margin_days')" />
        </div>
        <flux:text>O limite diário deve ser de 6 a 24 horas e não pode superar o semanal. O limite semanal deve ser de 30 a 40 horas; 40 horas é uma exceção que depende da previsão no projeto pedagógico. Alterações neste cadastro não recalculam os snapshots dos estágios formalizados.</flux:text>
    </section>
</div>
