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
</div>
