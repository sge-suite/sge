<?php

namespace Database\Factories;

use App\Enums\HolidayScope;
use App\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Holiday> */
class HolidayFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => fake()->unique()->sentence(3), 'date' => '2026-06-15', 'scope' => HolidayScope::National, 'origin' => 'manual'];
    }
}
