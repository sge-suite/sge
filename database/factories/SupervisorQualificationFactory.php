<?php

namespace Database\Factories;

use App\Enums\EducationLevel;
use App\Enums\SupervisorQualificationType;
use App\Models\SupervisorQualification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupervisorQualification> */
class SupervisorQualificationFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'type' => SupervisorQualificationType::Academic,
            'training_course' => 'Administração', 'education_level' => EducationLevel::Undergraduate,
            'experience_area' => null, 'experience_time' => null];
    }

    public function professional(): static
    {
        return $this->state(fn (): array => ['type' => SupervisorQualificationType::Professional,
            'training_course' => null, 'education_level' => null,
            'experience_area' => 'Gestão de produção', 'experience_time' => '5 anos']);
    }
}
