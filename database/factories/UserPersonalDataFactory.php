<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use App\Models\UserPersonalData;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPersonalData>
 */
class UserPersonalDataFactory extends Factory
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
            'rg' => fake()->numerify('##.###.###-#'),
            'rg_issuer' => 'SSP/RS',
            'rg_issue_date' => fake()->dateTimeBetween('-50 years', '-1 year'),
            'birth_date' => fake()->dateTimeBetween('-80 years', '-16 years'),
            'phone' => fake()->numerify('(##) #####-####'),
            'job_role' => null,
            'qualification' => null,
            'training' => null,
            'professional_experience' => null,
            'address_id' => Address::factory(),
        ];
    }

    /**
     * Indicate that the profile has no optional personal data yet.
     */
    public function withoutOptionalData(): static
    {
        return $this->state(fn (): array => [
            'rg' => null,
            'rg_issuer' => null,
            'rg_issue_date' => null,
            'birth_date' => null,
            'phone' => null,
            'job_role' => null,
            'qualification' => null,
            'training' => null,
            'professional_experience' => null,
            'address_id' => null,
        ]);
    }
}
