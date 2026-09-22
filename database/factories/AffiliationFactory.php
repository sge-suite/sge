<?php

namespace Database\Factories;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Affiliation>
 */
class AffiliationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'campus_id' => Campus::factory(),
            'type' => AffiliationType::InternshipOffice,
            'registration_number' => fake()->numerify('EMP-########'),
            'email' => fake()->safeEmail(),
            'deactivated_at' => null,
            'last_used_at' => null,
        ];
    }

    public function global(): static
    {
        return $this->state(fn (): array => [
            'campus_id' => null,
            'type' => AffiliationType::SystemAdministrator,
        ]);
    }

    public function onCampus(): static
    {
        return $this->state(fn (): array => [
            'campus_id' => Campus::factory(),
        ]);
    }

    public function server(): static
    {
        return $this->state(fn (): array => [
            'type' => AffiliationType::InternshipOffice,
            'registration_number' => fake()->numerify('EMP-########'),
        ]);
    }

    public function student(): static
    {
        return $this->state(fn (): array => [
            'type' => AffiliationType::Student,
            'registration_number' => fake()->unique()->numerify('STU-########'),
        ]);
    }

    public function supervisor(): static
    {
        return $this->state(fn (): array => [
            'type' => AffiliationType::Supervisor,
            'registration_number' => null,
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (): array => [
            'deactivated_at' => now(),
        ]);
    }

    public function recentlyUsed(): static
    {
        return $this->state(fn (): array => [
            'last_used_at' => now()->subMinute(),
        ]);
    }
}
