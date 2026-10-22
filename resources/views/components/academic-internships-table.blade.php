@props(['internships', 'paginated' => false, 'isOffice' => false])

@php($showGrades = in_array(app(\App\Support\StudentInternshipContext::class)->selected(auth()->user())->type, [\App\Enums\AffiliationType::Coordinator, \App\Enums\AffiliationType::Advisor], true))

<flux:table :paginate="$paginated ? $internships : null" container:class="[&>ui-table-scroll-area]:max-h-[70vh]">
    <flux:table.columns sticky class="bg-white dark:bg-zinc-800">
        <flux:table.column>Estágio / Discente</flux:table.column>
        <flux:table.column class="hidden md:table-cell">Curso / Orientador</flux:table.column>
        <flux:table.column>Situação</flux:table.column>
        <flux:table.column class="hidden lg:table-cell">Início / Término previsto</flux:table.column>
        @if ($showGrades)<flux:table.column class="hidden xl:table-cell">Notas</flux:table.column>@endif
    </flux:table.columns>
    <flux:table.rows>
        @foreach ($internships as $internship)
            <flux:table.row :key="'internship-'.$internship->id" class="group cursor-pointer transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-internship-link]').click()">
                <flux:table.cell class="whitespace-normal">
                    <a data-internship-link href="{{ route('internships.show', $internship) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">Nº {{ $internship->id }} · {{ $internship->student_snapshot['name'] ?? 'Discente' }}</a>
                    <flux:text size="sm">Matrícula: {{ $internship->student_snapshot['registration_number'] ?? 'Não informada' }}</flux:text>
                    <flux:text size="sm">{{ $internship->student_snapshot['email'] ?? 'E-mail não informado' }}</flux:text>
                </flux:table.cell>
                <flux:table.cell class="hidden whitespace-normal md:table-cell">{{ $internship->course?->name }}<flux:text size="sm">{{ $internship->advisorAffiliation?->user?->name ?? 'Não informado' }}</flux:text></flux:table.cell>
                <flux:table.cell><flux:badge size="sm" :color="match ($internship->status) { \App\Enums\InternshipStatus::Released, \App\Enums\InternshipStatus::InProgress => 'green', \App\Enums\InternshipStatus::AwaitingSignatures, \App\Enums\InternshipStatus::Paused => 'amber', \App\Enums\InternshipStatus::PendingCorrection => 'orange', \App\Enums\InternshipStatus::Completed => 'blue', \App\Enums\InternshipStatus::Cancelled => 'red', default => 'zinc' }">{{ $internship->status->label() }}</flux:badge>
                    @if ($isOffice && ($internship->pending_cancellations_count ?? 0) > 0)<flux:text size="sm" class="mt-2">Pedido de cancelamento pendente</flux:text>@endif
                </flux:table.cell>
                <flux:table.cell class="hidden lg:table-cell">{{ formatShort($internship->planned_start_date) }}<flux:text size="sm">{{ formatShort($internship->projected_end_date) }}</flux:text></flux:table.cell>
                @if ($showGrades)
                    <flux:table.cell class="hidden whitespace-normal xl:table-cell">
                        @if ($internship->advisorAffiliation?->user_id === auth()->id())
                            <flux:text size="sm">Supervisor: {{ $internship->supervisor_grade ?? 'Pendente' }} / {{ $internship->internship_type_snapshot['rules']['supervisor_evaluation_weight'] ?? '—' }}</flux:text>
                            <flux:text size="sm">Relatório: {{ $internship->report_grade ?? 'Pendente' }} / {{ $internship->internship_type_snapshot['rules']['report_weight'] ?? '—' }}</flux:text>
                            <flux:text size="sm">Apresentação: {{ $internship->presentation_grade ?? 'Pendente' }} / {{ $internship->internship_type_snapshot['rules']['presentation_weight'] ?? '—' }}</flux:text>
                        @endif
                        <flux:text size="sm">Consolidada: {{ $internship->consolidated_grade ?? 'Pendente' }} / 10</flux:text>
                    </flux:table.cell>
                @endif
            </flux:table.row>
        @endforeach
    </flux:table.rows>
</flux:table>
