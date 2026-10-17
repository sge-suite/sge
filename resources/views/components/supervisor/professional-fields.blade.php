@props(['values' => [], 'models' => ['job_role' => 'values.job_role'], 'qualifications' => []])

<div class="space-y-5">
    <flux:input name="job_role" wire:model="{{ $models['job_role'] }}" :value="$values['job_role'] ?? ''" error:name="job_role" label="Cargo ou função atual" maxlength="255" />
    <flux:text size="sm">Adicione suas formações e experiências nas diferentes áreas de atuação. O Setor de Estágio escolherá a qualificação adequada a cada estágio. Os itens só serão alterados ao salvar este formulário.</flux:text>
    <flux:error name="qualifications" />
    @if ($qualifications === [])
        <input type="hidden" name="qualifications" value="" />
        <flux:text>Nenhuma formação ou experiência informada.</flux:text>
    @endif
    @foreach ($qualifications as $key => $qualification)
        @php($academic = ($qualification['type'] ?? '') === \App\Enums\SupervisorQualificationType::Academic->value)
        <div wire:key="qualification-{{ $key }}" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex items-center justify-between gap-3">
                <flux:heading level="3">{{ $academic ? 'Formação acadêmica' : 'Experiência profissional' }}</flux:heading>
                <flux:button type="button" size="sm" variant="ghost" wire:click="removeSupervisorQualification('{{ $key }}')">Remover</flux:button>
            </div>
            <input type="hidden" name="qualifications[{{ $key }}][id]" value="{{ $qualification['id'] ?? '' }}" />
            <input type="hidden" name="qualifications[{{ $key }}][type]" value="{{ $qualification['type'] }}" />
            <flux:error name="qualifications.{{ $key }}.id" />
            <flux:error name="qualifications.{{ $key }}.type" />
            <div class="grid items-start gap-5 sm:grid-cols-2">
                @if ($academic)
                    <flux:input name="qualifications[{{ $key }}][training_course]" wire:model="qualifications.{{ $key }}.training_course" :value="$qualification['training_course'] ?? ''" error:name="qualifications.{{ $key }}.training_course" label="Curso de formação" maxlength="255" required />
                    <x-select name="qualifications[{{ $key }}][education_level]" wire:model="qualifications.{{ $key }}.education_level" :value="$qualification['education_level'] ?? ''" label="Nível de formação" placeholder="Selecione" :options="\App\Enums\EducationLevel::options()" :error="$errors->first('qualifications.'.$key.'.education_level')" required />
                @else
                    <flux:input name="qualifications[{{ $key }}][experience_area]" wire:model="qualifications.{{ $key }}.experience_area" :value="$qualification['experience_area'] ?? ''" error:name="qualifications.{{ $key }}.experience_area" label="Área ou atividade" placeholder="Ex.: Controle de qualidade" maxlength="255" required />
                    <flux:input name="qualifications[{{ $key }}][experience_time]" wire:model="qualifications.{{ $key }}.experience_time" :value="$qualification['experience_time'] ?? ''" error:name="qualifications.{{ $key }}.experience_time" label="Tempo de experiência" placeholder="Ex.: 3 anos" maxlength="255" required />
                @endif
            </div>
        </div>
    @endforeach
    <div class="flex flex-wrap gap-3">
        <flux:button type="button" variant="outline" wire:click="addSupervisorQualification('academic')" :disabled="count($qualifications) >= 25" wire:loading.attr="disabled">Adicionar formação</flux:button>
        <flux:button type="button" variant="outline" wire:click="addSupervisorQualification('professional')" :disabled="count($qualifications) >= 25" wire:loading.attr="disabled">Adicionar experiência</flux:button>
    </div>
</div>
