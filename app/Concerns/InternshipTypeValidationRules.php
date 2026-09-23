<?php

namespace App\Concerns;

use App\Enums\EvaluationConcept;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait InternshipTypeValidationRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function internshipTypeRules(bool $requiresActiveCourse): array
    {
        $courseExists = $requiresActiveCourse
            ? Rule::exists('courses', 'id')->whereNull('deactivated_at')
            : Rule::exists('courses', 'id');

        return [
            'course_id' => ['bail', 'required', 'integer', $courseExists],
            'name' => ['required', 'string', 'max:255'],
            'required_hours' => ['required', 'integer', 'min:1'],
            'supervisor_evaluation_weight' => ['required', 'integer', 'min:1'],
            'report_weight' => ['required', 'integer', 'min:1'],
            'presentation_weight' => ['required', 'integer', 'min:1'],
            'very_good_value' => ['required', 'numeric', 'decimal:0,1', 'min:0'],
            'good_value' => ['required', 'numeric', 'decimal:0,1', 'min:0'],
            'satisfactory_value' => ['required', 'numeric', 'decimal:0,1', 'min:0'],
            'unsatisfactory_value' => ['required', 'numeric', 'decimal:0,1', 'min:0'],
            'max_daily_hours' => ['required', 'integer', 'min:6'],
            'max_weekly_hours' => ['required', 'integer', 'min:30'],
            'safety_margin_days' => ['required', 'integer', 'min:0'],
            'deactivated_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function internshipTypeValidationMessages(): array
    {
        return [
            'required' => 'O campo :attribute é obrigatório.',
            'integer' => 'O campo :attribute deve ser um número inteiro.',
            'numeric' => 'O campo :attribute deve ser numérico.',
            'decimal' => 'O campo :attribute deve ter no máximo uma casa decimal.',
            'min' => 'O campo :attribute deve ser no mínimo :min.',
            'string' => 'O campo :attribute deve ser texto.',
            'max' => 'O campo :attribute deve ter no máximo :max caracteres.',
            'exists' => 'O curso informado não está disponível.',
            'date' => 'O campo :attribute deve conter uma data válida.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function internshipTypeValidationAttributes(): array
    {
        return [
            'course_id' => 'curso',
            'name' => 'nome do tipo de estágio',
            'required_hours' => 'carga horária obrigatória',
            'supervisor_evaluation_weight' => 'peso da avaliação do supervisor',
            'report_weight' => 'peso do relatório',
            'presentation_weight' => 'peso da apresentação',
            'very_good_value' => 'valor do conceito Muito bom',
            'good_value' => 'valor do conceito Bom',
            'satisfactory_value' => 'valor do conceito Satisfatório',
            'max_daily_hours' => 'limite diário de horas',
            'max_weekly_hours' => 'limite semanal de horas',
            'safety_margin_days' => 'margem de segurança em dias',
            'deactivated_at' => 'data de desativação',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function validateInternshipTypeValues(Validator $validator, array $attributes): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $weights = [
            (int) $attributes['supervisor_evaluation_weight'],
            (int) $attributes['report_weight'],
            (int) $attributes['presentation_weight'],
        ];
        $conceptValues = [];

        foreach (EvaluationConcept::cases() as $concept) {
            if ($concept === EvaluationConcept::Excellent) {
                $conceptValues[$concept->value] = (float) $weights[0];

                continue;
            }

            $attribute = $concept->internshipTypeValueAttribute();

            if ($attribute !== null) {
                $conceptValues[$concept->value] = (float) $attributes[$attribute];
            }
        }

        if (array_sum($weights) !== 10) {
            $validator->errors()->add('supervisor_evaluation_weight', 'A soma dos pesos deve ser exatamente 10.');
        }

        $previousValue = null;

        foreach (EvaluationConcept::cases() as $concept) {
            $value = $conceptValues[$concept->value];
            $attribute = $concept->internshipTypeValueAttribute();

            if ($attribute !== null && $value > $weights[0]) {
                $validator->errors()->add($attribute, "O valor do conceito {$concept->label()} não pode ultrapassar o peso da avaliação do supervisor.");
            }

            if ($attribute !== null && $previousValue !== null && $value >= $previousValue) {
                $validator->errors()->add($attribute, 'Os valores dos conceitos devem ser estritamente decrescentes e não podem ser iguais.');
            }

            $previousValue = $value;
        }
    }
}
