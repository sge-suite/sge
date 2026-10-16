@props(['values' => [], 'models' => [
    'job_role' => 'values.job_role',
    'training_course' => 'values.training_course',
    'education_level' => 'values.education_level',
    'experience_time' => 'values.experience_time',
]])

<div class="space-y-5">
    <flux:text size="sm">Estes dados são uma referência para preencher avaliações. Você poderá confirmar os dados ou informar outros em cada avaliação, conforme a área do estagiário. Todos os campos aqui são opcionais; deixe um campo vazio para limpar seu valor.</flux:text>

    <flux:input name="job_role" wire:model="{{ $models['job_role'] }}" :value="$values['job_role'] ?? ''" error:name="job_role" label="Cargo/Função que atua" maxlength="255" />

    <flux:fieldset class="space-y-4">
        <flux:legend>Formação acadêmica</flux:legend>
        <div class="grid items-start gap-5 sm:grid-cols-2">
            <flux:input name="training_course" wire:model="{{ $models['training_course'] }}" :value="$values['training_course'] ?? ''" error:name="training_course" label="Curso de formação" maxlength="255" />
            <x-select name="education_level" wire:model="{{ $models['education_level'] }}" :value="$values['education_level'] ?? ''" label="Nível" :options="['' => 'Não informado', ...\App\Enums\EducationLevel::options()]" :error="$errors->first('education_level')" />
        </div>
    </flux:fieldset>

    <flux:fieldset class="space-y-4">
        <flux:legend>Experiência profissional</flux:legend>
        <flux:input name="experience_time" wire:model="{{ $models['experience_time'] }}" :value="$values['experience_time'] ?? ''" error:name="experience_time" label="Tempo de experiência na função" maxlength="255" />
    </flux:fieldset>
</div>
