@props(['internship', 'evaluation', 'weights'])

@can('viewSupervisorEvaluation', $internship)
    @if ($evaluation->internship_id === $internship->id)
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading level="3">{{ $evaluation->supervisorAffiliation?->user?->name ?? 'Supervisor não informado' }}</flux:heading>
                <div class="flex flex-wrap gap-2">
                    <flux:badge>{{ $evaluation->status->label() }}</flux:badge>
                    @if ($evaluation->status === \App\Enums\EvaluationStatus::Approved && $internship->current_supervisor_evaluation_id === $evaluation->id)
                        <flux:badge color="green">Vigente</flux:badge>
                    @endif
                </div>
            </div>
            <dl class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ([
                    'Envio' => $evaluation->submitted_at ? formatDateTime($evaluation->submitted_at) : 'Ainda não enviada',
                    'Carga horária cumprida' => match ($evaluation->hours_requirement_met) { true => 'Sim', false => 'Não', default => 'Não informado' },
                    'Horas faltantes' => $evaluation->estimated_hours_remaining !== null ? $evaluation->estimated_hours_remaining.' h' : null,
                ] as $label => $value)
                    @if ($value !== null)
                        <div wire:key="evaluation-{{ $evaluation->id }}-general-{{ $loop->index }}"><dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $value }}</dd></div>
                    @endif
                @endforeach
            </dl>
            @can('viewSupervisorEvaluationInternalObservations', $internship)
                @if ($evaluation->status === \App\Enums\EvaluationStatus::Approved && $internship->current_supervisor_evaluation_id === $evaluation->id)
                    <flux:text>Nota do supervisor: {{ $internship->supervisor_grade ?? 'Pendente' }} / {{ $weights['supervisor_evaluation_weight'] ?? '—' }}</flux:text>
                @endif
                @if ($evaluation->reviewed_at)<flux:text>Analisada em {{ formatDateTime($evaluation->reviewed_at) }}.</flux:text>@endif
                @if (filled($evaluation->review_notes))<div><flux:heading level="3">Observações da análise</flux:heading><flux:text class="mt-2 whitespace-pre-line">{{ $evaluation->review_notes }}</flux:text></div>@endif
                @if (filled($evaluation->cancellation_reason))<div><flux:heading level="3">Motivo do cancelamento</flux:heading><flux:text class="mt-2 whitespace-pre-line">{{ $evaluation->cancellation_reason }}</flux:text></div>@endif
            @endcan
            @if ($evaluation->hours_requirement_met === true)
                <div class="space-y-4">
                    <flux:heading level="3">Formação e atuação</flux:heading>
                    <dl class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ([
                            'Cargo / função' => $evaluation->job_role ?? 'Não informado',
                            'Qualificação' => match ($evaluation->has_academic_background) { true => 'Formação na área', false => 'Experiência profissional na área', default => 'Não informado' },
                            'Curso de formação' => $evaluation->has_academic_background === true ? ($evaluation->training_course ?? 'Não informado') : null,
                            'Nível de escolaridade' => $evaluation->has_academic_background === true ? ($evaluation->education_level?->label() ?? 'Não informado') : null,
                            'Área e tempo de experiência' => $evaluation->has_academic_background === false ? ($evaluation->experience_time ?? 'Não informado') : null,
                        ] as $label => $value)
                            @if ($value !== null)
                                <div wire:key="evaluation-{{ $evaluation->id }}-qualification-{{ $loop->index }}"><dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="mt-1 whitespace-pre-line text-sm text-zinc-900 dark:text-white">{{ $value }}</dd></div>
                            @endif
                        @endforeach
                    </dl>
                </div>
                @can('viewSupervisorEvaluationCriteria', $internship)
                    <div class="space-y-4">
                        <flux:heading level="3">Critérios da avaliação</flux:heading>
                        <div class="overflow-x-auto">
                            <flux:table>
                                <flux:table.columns><flux:table.column>Critério</flux:table.column><flux:table.column>Conceito</flux:table.column><flux:table.column>Valor</flux:table.column></flux:table.columns>
                                <flux:table.rows>
                                    @foreach (\App\Models\SupervisorEvaluation::criteria() as $criterion => $label)
                                        @php($concept = \App\Enums\EvaluationConcept::tryFrom($evaluation->{$criterion} ?? ''))
                                        <flux:table.row :key="'evaluation-'.$evaluation->id.'-criterion-'.$criterion">
                                            <flux:table.cell>{{ $label }}</flux:table.cell>
                                            <flux:table.cell>{{ $concept?->label() ?? 'Não informado' }}</flux:table.cell>
                                            <flux:table.cell>{{ $concept === \App\Enums\EvaluationConcept::Excellent ? ($weights['supervisor_evaluation_weight'] ?? '—') : ($internship->internship_type_snapshot['rules'][$concept?->internshipTypeValueAttribute() ?? ''] ?? '—') }}</flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </div>
                    </div>
                @endcan
                @foreach (\App\Models\SupervisorEvaluation::comments() as $field => $label)
                    @if (filled($evaluation->{$field}) && match ($field) {
                        'suggestions_to_institution' => auth()->user()->can('viewSupervisorEvaluationCriteria', $internship),
                        default => true,
                    })
                        <div wire:key="evaluation-{{ $evaluation->id }}-comment-{{ $field }}"><flux:heading level="3">{{ $label }}</flux:heading><flux:text class="mt-2 whitespace-pre-line">{{ $evaluation->{$field} }}</flux:text></div>
                    @endif
                @endforeach
            @endif
        </div>
    @endif
@endcan
