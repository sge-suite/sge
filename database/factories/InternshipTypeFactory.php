<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\InternshipType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InternshipType>
 */
class InternshipTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'name' => fake()->unique()->words(3, true),
            'required_hours' => 300,
            'supervisor_evaluation_weight' => 4,
            'report_weight' => 3,
            'presentation_weight' => 3,
            'very_good_value' => 3,
            'good_value' => 2,
            'satisfactory_value' => 1,
            'unsatisfactory_value' => 0,
            'max_daily_hours' => 6,
            'max_weekly_hours' => 30,
            'safety_margin_days' => 7,
            'deactivated_at' => null,
        ];
    }

    public function deactivated(): static
    {
        return $this->state(fn (): array => ['deactivated_at' => now()]);
    }
}
