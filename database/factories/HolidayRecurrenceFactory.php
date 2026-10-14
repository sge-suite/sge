<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\HolidayRecurrence;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HolidayRecurrence> */
class HolidayRecurrenceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => fake()->unique()->sentence(3), 'city_id' => City::factory(), 'month' => 6, 'day' => 15, 'starts_year' => null, 'ends_year' => null];
    }
}
