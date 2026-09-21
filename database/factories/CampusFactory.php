<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\Campus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campus>
 */
class CampusFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' - '.fake()->city(),
            'cnpj' => '04.252.011/0001-10',
            'phone' => '(55) 99999-9999',
            'email' => fake()->safeEmail(),
            'address_id' => Address::factory(),
            'legal_representative_name' => fake()->name(),
            'legal_representative_position' => 'Diretor(a) Geral',
            'insurance_company_name' => null,
            'insurance_policy_number' => null,
            'deactivated_at' => null,
        ];
    }

    /**
     * Indicate that the campus has its institutional insurance configured.
     */
    public function withInsurance(): static
    {
        return $this->state(fn (): array => [
            'insurance_company_name' => fake()->company(),
            'insurance_policy_number' => fake()->bothify('APOL-####??'),
        ]);
    }

    /**
     * Indicate that the campus has been deactivated.
     */
    public function deactivated(): static
    {
        return $this->state(fn (): array => [
            'deactivated_at' => now(),
        ]);
    }
}
