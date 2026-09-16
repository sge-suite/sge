<?php

namespace Database\Factories;

use App\Enums\BrazilianState;
use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ibge_code' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'name' => fake()->city(),
            'state' => fake()->randomElement(BrazilianState::cases()),
        ];
    }
}
