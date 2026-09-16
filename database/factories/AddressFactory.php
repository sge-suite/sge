<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'street' => fake()->streetName(),
            'number' => (string) fake()->buildingNumber(),
            'neighborhood' => 'Centro',
            'zip_code' => fake()->numerify('########'),
        ];
    }
}
