<?php

namespace Database\Factories;

use App\Enums\EducationLevel;
use App\Enums\RegistrationRequestStatus;
use App\Models\SupervisorRegistrationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupervisorRegistrationRequest>
 */
class SupervisorRegistrationRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'cpf' => fake()->unique()->format('cpf', [false]),
            'phone' => '(55) 99999-9999',
            'email' => fake()->safeEmail(),
            'job_role' => 'Supervisora de estágio',
            'has_academic_background' => true,
            'training_course' => 'Administração',
            'education_level' => EducationLevel::UndergraduateTechnologist,
            'experience_time' => null,
            'status' => RegistrationRequestStatus::Draft,
            'supervisor_affiliation_id' => null,
            'reviewed_at' => null,
            'decision_reason' => null,
        ];
    }
}
