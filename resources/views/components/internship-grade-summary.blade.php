@props(['internship', 'showComponents' => false])

@if ($showComponents)
    <flux:text size="sm">Supervisor: {{ $internship->supervisor_grade ?? 'Pendente' }} / {{ $internship->internship_type_snapshot['rules']['supervisor_evaluation_weight'] ?? '—' }}</flux:text>
    <flux:text size="sm">Relatório: {{ $internship->report_grade ?? 'Pendente' }} / {{ $internship->internship_type_snapshot['rules']['report_weight'] ?? '—' }}</flux:text>
    <flux:text size="sm">Apresentação: {{ $internship->presentation_grade ?? 'Pendente' }} / {{ $internship->internship_type_snapshot['rules']['presentation_weight'] ?? '—' }}</flux:text>
@endif
<flux:text size="sm">Consolidada: {{ $internship->consolidated_grade ?? 'Pendente' }} / 10</flux:text>
