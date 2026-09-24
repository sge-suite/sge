<?php

namespace Database\Factories;

use App\Models\Internship;
use App\Models\InternshipCalendarOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InternshipCalendarOverride> */
class InternshipCalendarOverrideFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'internship_id' => Internship::factory(),
            'date' => now()->addMonths(2)->toDateString(),
            'is_working_day' => false,
            'reason' => 'Fechamento excepcional do local de estágio.',
        ];
    }
}
