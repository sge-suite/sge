<?php

namespace Database\Factories;

use App\Enums\EvaluationConcept;
use App\Enums\EvaluationStatus;
use App\Models\Internship;
use App\Models\SupervisorEvaluation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupervisorEvaluation>
 */
class SupervisorEvaluationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'internship_id' => Internship::factory(),
            'supervisor_affiliation_id' => fn (array $attributes): int => Internship::findOrFail($attributes['internship_id'])->supervisor_affiliation_id,
            'status' => EvaluationStatus::Draft,
            'has_academic_background' => null,
            'training_course' => null,
            'education_level' => null,
            'job_role' => null,
            'experience_time' => null,
            'hours_requirement_met' => null,
            'estimated_hours_remaining' => null,
            'performance' => null,
            'comprehension' => null,
            'technical_knowledge' => null,
            'organization' => null,
            'initiative' => null,
            'attendance' => null,
            'discipline' => null,
            'sociability' => null,
            'cooperation' => null,
            'responsibility' => null,
            'considerations' => null,
            'suggestions_to_institution' => null,
            'performance_issues' => null,
            'other_observations' => null,
            'submitted_at' => null,
            'reviewed_at' => null,
            'review_notes' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (): array => [
            'status' => EvaluationStatus::Submitted,
            'has_academic_background' => true,
            'training_course' => 'Tecnologia em Sistemas',
            'education_level' => 'superior',
            'job_role' => 'Supervisor de estágio',
            'experience_time' => null,
            'hours_requirement_met' => true,
            'performance' => EvaluationConcept::Good->value,
            'comprehension' => EvaluationConcept::Good->value,
            'technical_knowledge' => EvaluationConcept::Good->value,
            'organization' => EvaluationConcept::Good->value,
            'initiative' => EvaluationConcept::Good->value,
            'attendance' => EvaluationConcept::Good->value,
            'discipline' => EvaluationConcept::Good->value,
            'sociability' => EvaluationConcept::Good->value,
            'cooperation' => EvaluationConcept::Good->value,
            'responsibility' => EvaluationConcept::Good->value,
            'submitted_at' => now(),
        ]);
    }

    public function returned(): static
    {
        return $this->submitted()->state(fn (): array => [
            'status' => EvaluationStatus::Returned,
            'reviewed_at' => now(),
            'review_notes' => 'Corrigir a avaliação.',
        ]);
    }
}
