<?php

namespace App\Support;

use App\Models\Internship;

class InternshipEmailDetails
{
    /** @return array<string, string> */
    public function forInternship(Internship $internship): array
    {
        $internship->loadMissing('course.campus');

        return [
            'Estágio' => 'nº '.$internship->id,
            'Tipo de estágio' => $internship->internship_type_snapshot['name'] ?? 'Não informado',
            'Discente' => $internship->student_snapshot['name'] ?? 'Não informado',
            'Curso' => $internship->course->name,
            'Campus' => $internship->course->campus->name,
            'Parte concedente' => $internship->granting_party_snapshot['name'] ?? 'Não informada',
            'Início previsto' => formatShort($internship->planned_start_date),
            'Término previsto' => formatShort($internship->projected_end_date),
        ];
    }

    /** @param array<string, string> $details */
    public function text(array $details): string
    {
        $lines = [];
        foreach ($details as $label => $value) {
            $lines[] = $label.': '.$value;
        }

        return implode("\n", $lines);
    }
}
