@props(['internship', 'weights'])

<div class="space-y-8">
    @foreach ([
        'Dados do discente' => [
            'Nome completo' => $internship->student_snapshot['name'] ?? 'Não informado',
            'Matrícula' => $internship->student_snapshot['registration_number'] ?? 'Não informada',
            'Curso' => $internship->course->name,
            'E-mail' => $internship->student_snapshot['email'] ?? 'Não informado',
            'Telefone' => $internship->student_snapshot['phone'] ?? 'Não informado',
        ],
        'Parte concedente' => [
            'Nome' => $internship->granting_party_snapshot['name'] ?? 'Não informado',
            'Telefone' => $internship->granting_party_snapshot['phone'] ?? 'Não informado',
            'E-mail' => $internship->granting_party_snapshot['email'] ?? 'Não informado',
            'Representante' => $internship->granting_party_snapshot['representative_name'] ?? 'Não informado',
            'Área de atividade' => $internship->granting_party_snapshot['field_of_activity'] ?? 'Não informada',
        ],
        'Supervisor' => [
            'Nome' => $internship->supervisor_snapshot['name'] ?? 'Não informado',
            'E-mail' => $internship->supervisor_snapshot['email'] ?? 'Não informado',
            'Telefone' => $internship->supervisor_snapshot['phone'] ?? 'Não informado',
            'Cargo / função' => $internship->supervisor_snapshot['job_role'] ?? 'Não informado',
        ],
        'Dados do estágio' => [
            'Tipo de estágio' => $internship->internship_type_snapshot['name'] ?? 'Não informado',
            'Setor de atuação' => $internship->internship_sector ?? 'Não informado',
            'Orientador' => $internship->advisorAffiliation?->user?->name ?? 'Não informado',
            'Co-orientador' => $internship->co_advisor_name ?? 'Não informado',
            'Carga horária exigida' => ($internship->internship_type_snapshot['rules']['required_hours'] ?? '—').' h',
            'Peso da avaliação do supervisor' => $weights['supervisor_evaluation_weight'] ?? 'Não configurado',
            'Início previsto' => formatShort($internship->planned_start_date),
            'Término previsto' => formatShort($internship->projected_end_date),
            'Duração prevista' => (int) $internship->planned_start_date->diffInDays($internship->projected_end_date).' dias',
        ],
    ] as $heading => $fields)
        <section wire:key="academic-section-{{ $loop->index }}" class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <flux:heading level="2" size="lg">{{ $heading }}</flux:heading>
            <dl class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($fields as $label => $value)
                    <div wire:key="academic-field-{{ $loop->parent->index }}-{{ $loop->index }}"><dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="mt-1 text-sm wrap-break-word text-zinc-900 dark:text-white">{{ $value }}</dd></div>
                @endforeach
            </dl>
        </section>
    @endforeach
    <section class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700">
        <flux:heading level="2" size="lg">Atividades</flux:heading>
        <flux:text class="whitespace-pre-line">{{ $internship->activities }}</flux:text>
    </section>
    @php($evaluation = $internship->currentSupervisorEvaluation)
    @if ($evaluation?->status === \App\Enums\EvaluationStatus::Approved && $evaluation->internship_id === $internship->id)
        <section class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <flux:heading level="2" size="lg">Avaliação do supervisor</flux:heading>
            <dl class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ([
                    'Supervisor que respondeu' => $evaluation->supervisorAffiliation?->user?->name ?? 'Não informado',
                    'Carga horária cumprida' => $evaluation->hours_requirement_met ? 'Sim' : 'Não',
                    'Curso de formação' => $evaluation->training_course ?? 'Não informado',
                    'Nível de escolaridade' => $evaluation->education_level?->label() ?? 'Não informado',
                    'Cargo / função' => $evaluation->job_role ?? 'Não informado',
                    'Tempo de experiência' => $evaluation->experience_time ?? 'Não informado',
                ] as $label => $value)
                    <div wire:key="evaluation-field-{{ $loop->index }}"><dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $value }}</dd></div>
                @endforeach
            </dl>
            @if (app(\App\Support\StudentInternshipContext::class)->selected(auth()->user())->type === \App\Enums\AffiliationType::Coordinator)
                <flux:heading level="3">Critérios da avaliação</flux:heading>
                <flux:table>
                    <flux:table.columns><flux:table.column>Critério</flux:table.column><flux:table.column>Conceito</flux:table.column><flux:table.column>Valor</flux:table.column></flux:table.columns>
                    <flux:table.rows>
                        @foreach (['performance' => 'Desempenho', 'comprehension' => 'Compreensão', 'technical_knowledge' => 'Conhecimento técnico', 'organization' => 'Organização', 'initiative' => 'Iniciativa', 'attendance' => 'Assiduidade', 'discipline' => 'Disciplina', 'sociability' => 'Sociabilidade', 'cooperation' => 'Cooperação', 'responsibility' => 'Responsabilidade'] as $criterion => $label)
                            @php($concept = \App\Enums\EvaluationConcept::tryFrom($evaluation->{$criterion} ?? ''))
                            <flux:table.row :key="'evaluation-criterion-'.$criterion">
                                <flux:table.cell>{{ $label }}</flux:table.cell>
                                <flux:table.cell>{{ $concept?->label() ?? 'Não informado' }}</flux:table.cell>
                                <flux:table.cell>{{ $concept === \App\Enums\EvaluationConcept::Excellent ? ($weights['supervisor_evaluation_weight'] ?? '—') : ($internship->internship_type_snapshot['rules'][$concept?->internshipTypeValueAttribute() ?? ''] ?? '—') }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
                @if ($evaluation->suggestions_to_institution)
                    <div><flux:heading level="3">Sugestões à instituição</flux:heading><flux:text class="mt-2 whitespace-pre-line">{{ $evaluation->suggestions_to_institution }}</flux:text></div>
                @endif
            @endif
            @if ($evaluation->considerations)
                <div><flux:heading level="3">Considerações</flux:heading><flux:text class="mt-2 whitespace-pre-line">{{ $evaluation->considerations }}</flux:text></div>
            @endif
            @if ($evaluation->performance_issues)
                <div><flux:heading level="3">Aspectos que prejudicaram o desempenho</flux:heading><flux:text class="mt-2 whitespace-pre-line">{{ $evaluation->performance_issues }}</flux:text></div>
            @endif
        </section>
    @endif
</div>
