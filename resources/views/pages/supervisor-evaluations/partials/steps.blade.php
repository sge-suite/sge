@if ($step === 0)
    <flux:text>Confirme o cumprimento da carga horária antes de preencher os demais itens da avaliação.</flux:text>
    <flux:callout icon="information-circle" heading="Carga horária exigida: {{ $rules['required_hours'] ?? '—' }} h"><flux:callout.text>Use a carga horária registrada neste estágio como referência para sua resposta.</flux:callout.text></flux:callout>
    <x-select wire:model.live="values.hours_requirement_met" :value="$values['hours_requirement_met'] ?? ''" label="A carga horária exigida para este estágio foi cumprida?" :options="['1' => 'Sim, a carga horária foi cumprida', '0' => 'Não, ainda faltam horas']" :invalid="$errors->has('hours_requirement_met')" :error="$errors->first('hours_requirement_met')" :disabled="! $editable" required />
    @if ((string) ($values['hours_requirement_met'] ?? '') === '0')
        <flux:input type="number" wire:model="values.estimated_hours_remaining" label="Quantas horas faltam?" description:trailing="Informe uma quantidade inteira, positiva e limitada à carga horária exigida." min="1" :max="min(65535, (int) ($rules['required_hours'] ?? 0))" step="1" :disabled="! $editable" error:name="estimated_hours_remaining" required />
        <flux:callout variant="warning" heading="Carga horária ainda não cumprida"><flux:callout.text>Você enviará somente a informação das horas faltantes. O Setor analisará a resposta e devolverá a avaliação com orientações para o preenchimento posterior.</flux:callout.text></flux:callout>
    @endif
@elseif ($step === 1)
    <flux:text>Confira sua formação ou experiência relacionada às atividades deste estágio. Os dados de referência podem ser ajustados nesta avaliação.</flux:text>
    <flux:input wire:model="values.job_role" label="Cargo ou função na concedente" maxlength="255" :disabled="! $editable" error:name="job_role" required />
    <x-select wire:model.live="values.has_academic_background" :value="$values['has_academic_background'] ?? ''" label="Qualificação relacionada às atividades" :options="['1' => 'Formação na área', '0' => 'Experiência profissional na área']" :invalid="$errors->has('has_academic_background')" :error="$errors->first('has_academic_background')" :disabled="! $editable" required />
    @if ((string) ($values['has_academic_background'] ?? '') === '1')
        <div class="grid items-start gap-5 sm:grid-cols-2">
            <flux:input wire:model="values.training_course" label="Curso de formação" placeholder="Ex.: Técnico em Administração" maxlength="255" :disabled="! $editable" error:name="training_course" required />
            <x-select wire:model="values.education_level" :value="$values['education_level'] ?? ''" label="Nível de formação" :options="\App\Enums\EducationLevel::options()" :invalid="$errors->has('education_level')" :error="$errors->first('education_level')" :disabled="! $editable" required />
        </div>
    @elseif ((string) ($values['has_academic_background'] ?? '') === '0')
        <flux:textarea wire:model="values.experience_time" label="Área e tempo de experiência profissional" placeholder="Ex.: 3 anos em controle de qualidade" maxlength="255" rows="3" :disabled="! $editable" error:name="experience_time" required />
    @endif
    <flux:text size="sm">Estas respostas pertencem à avaliação. Elas não alteram seu perfil nem os dados originais do estágio.</flux:text>
@elseif ($step === 2)
    <flux:text>Selecione um conceito para cada critério. Os valores seguem as regras registradas neste estágio, com peso máximo de {{ $rules['supervisor_evaluation_weight'] ?? '—' }} ponto(s).</flux:text>
    <div class="grid items-start gap-5 sm:grid-cols-2">
        @foreach (\App\Models\SupervisorEvaluation::criteria() as $criterion => $label)
            <div wire:key="criterion-{{ $criterion }}"><x-select wire:model="values.{{ $criterion }}" :value="$values[$criterion] ?? ''" :label="$label" :options="$this->conceptOptions" :invalid="$errors->has($criterion)" :error="$errors->first($criterion)" :disabled="! $editable" required /></div>
        @endforeach
    </div>
@elseif ($step === 3)
    <flux:text>Acrescente informações que possam ajudar o acompanhamento do estágio. Todos os campos desta etapa são opcionais.</flux:text>
    @foreach (\App\Models\SupervisorEvaluation::comments() as $field => $label)
        <div wire:key="comment-{{ $field }}"><flux:textarea wire:model="values.{{ $field }}" :label="$label.' (opcional)'" maxlength="5000" rows="3" :disabled="! $editable" :error:name="$field" /></div>
    @endforeach
@endif
