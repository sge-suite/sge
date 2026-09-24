<?php

namespace Database\Factories;

use App\Models\Internship;
use App\Models\InternshipPause;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InternshipPause> */
class InternshipPauseFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'internship_id' => Internship::factory()->state(['status' => 'in_progress']),
            'starts_at' => now()->addMonths(2)->toDateString(),
            'ends_at' => now()->addMonths(2)->addDays(2)->toDateString(),
            'reason' => 'Pausa autorizada pelo Setor de Estágio.',
        ];
    }
}
