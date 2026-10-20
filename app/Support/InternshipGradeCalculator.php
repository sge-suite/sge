<?php

namespace App\Support;

use App\Enums\EvaluationConcept;
use App\Enums\EvaluationStatus;
use App\Models\Internship;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InternshipGradeCalculator
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array{supervisor_evaluation_weight: int, report_weight: int, presentation_weight: int}
     */
    public function weights(array $rules): array
    {
        $weights = Validator::make($rules, [
            'supervisor_evaluation_weight' => ['required', 'integer', 'min:0', 'max:10'],
            'report_weight' => ['required', 'integer', 'min:0', 'max:10'],
            'presentation_weight' => ['required', 'integer', 'min:0', 'max:10'],
        ]);
        if ($weights->fails() || array_sum($weights->validated()) !== 10) {
            throw ValidationException::withMessages(['grade_weights' => 'Os pesos registrados no estágio devem estar configurados e somar 10. Solicite a conferência ao Setor de Estágios.']);
        }

        $values = $weights->validated();

        return [
            'supervisor_evaluation_weight' => (int) $values['supervisor_evaluation_weight'],
            'report_weight' => (int) $values['report_weight'],
            'presentation_weight' => (int) $values['presentation_weight'],
        ];
    }

    /** @return array{supervisor_grade: ?float, consolidated_grade: ?float} */
    public function calculate(Internship $internship, ?float $reportGrade, ?float $presentationGrade): array
    {
        $rules = $internship->internship_type_snapshot['rules'] ?? [];
        $weights = $this->weights($rules);
        Validator::make(['report_grade' => $reportGrade, 'presentation_grade' => $presentationGrade], [
            'report_grade' => ['nullable', 'numeric', 'decimal:0,1', 'min:0', 'max:'.$weights['report_weight']],
            'presentation_grade' => ['nullable', 'numeric', 'decimal:0,1', 'min:0', 'max:'.$weights['presentation_weight']],
        ])->validate();
        $supervisorGrade = $weights['supervisor_evaluation_weight'] === 0 ? 0.0 : null;
        $evaluation = $internship->currentSupervisorEvaluation;
        if ($evaluation !== null && $weights['supervisor_evaluation_weight'] > 0) {
            if ($evaluation->status !== EvaluationStatus::Approved || $evaluation->internship_id !== $internship->id) {
                throw ValidationException::withMessages(['supervisor_evaluation' => 'A avaliação vigente precisa estar aprovada e pertencer ao estágio.']);
            }
            $values = [];
            foreach (EvaluationConcept::cases() as $concept) {
                $value = match ($concept) {
                    EvaluationConcept::Excellent => $weights['supervisor_evaluation_weight'],
                    default => $rules[$concept->internshipTypeValueAttribute()] ?? null,
                };
                if (! is_numeric($value) || $value < 0 || $value > $weights['supervisor_evaluation_weight']
                    || ($values !== [] && $value >= end($values)) || round((float) $value, 1) !== (float) $value) {
                    throw ValidationException::withMessages(['grade_weights' => 'A escala de conceitos registrada no estágio é inválida. Solicite a conferência ao Setor de Estágios.']);
                }
                $values[$concept->value] = (float) $value;
            }
            $total = 0.0;
            foreach (['performance', 'comprehension', 'technical_knowledge', 'organization', 'initiative', 'attendance', 'discipline', 'sociability', 'cooperation', 'responsibility'] as $criterion) {
                $concept = $evaluation->{$criterion};
                if (! is_string($concept) || ! array_key_exists($concept, $values)) {
                    throw ValidationException::withMessages(['supervisor_evaluation' => 'A avaliação vigente deve conter os dez conceitos válidos.']);
                }
                $total += $values[$concept];
            }
            $supervisorGrade = round($total / 10, 1);
        }
        $reportGrade = $weights['report_weight'] === 0 ? 0.0 : $reportGrade;
        $presentationGrade = $weights['presentation_weight'] === 0 ? 0.0 : $presentationGrade;

        return [
            'supervisor_grade' => $supervisorGrade,
            'consolidated_grade' => $supervisorGrade === null || $reportGrade === null || $presentationGrade === null
                ? null : round($supervisorGrade + $reportGrade + $presentationGrade, 1),
        ];
    }
}
