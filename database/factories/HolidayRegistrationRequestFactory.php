<?php

namespace Database\Factories;

use App\Enums\RegistrationRequestStatus;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\City;
use App\Models\HolidayRegistrationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HolidayRegistrationRequest>
 */
class HolidayRegistrationRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campus_id' => Campus::factory(),
            'requested_by_affiliation_id' => Affiliation::factory()->server(),
            'reviewed_by_affiliation_id' => null,
            'kind' => 'holiday',
            'name' => 'Aniversário do Município',
            'city_id' => City::factory(),
            'date' => now()->addMonth()->toDateString(),
            'month' => null,
            'day' => null,
            'notes' => 'Feriado municipal instituído por lei local.',
            'status' => RegistrationRequestStatus::Submitted,
            'decision_notes' => null,
            'reviewed_at' => null,
            'holiday_id' => null,
            'holiday_recurrence_id' => null,
        ];
    }
}
