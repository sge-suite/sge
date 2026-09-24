<?php

namespace Database\Factories;

use App\Enums\AffiliationType;
use App\Enums\InternshipStatus;
use App\Models\Address;
use App\Models\Affiliation;
use App\Models\Course;
use App\Models\GrantingParty;
use App\Models\Internship;
use App\Models\InternshipType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Internship>
 */
class InternshipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'student_affiliation_id' => fn (array $attributes): int => Affiliation::factory()->student()->create([
                'course_id' => $attributes['course_id'],
                'campus_id' => Course::findOrFail($attributes['course_id'])->campus_id,
            ])->id,
            'advisor_affiliation_id' => Affiliation::factory()->state(['type' => AffiliationType::Advisor]),
            'supervisor_affiliation_id' => Affiliation::factory()->supervisor(),
            'student_address_id' => Address::factory(),
            'internship_type_id' => fn (array $attributes): int => InternshipType::factory()->create([
                'course_id' => $attributes['course_id'],
            ])->id,
            'granting_party_id' => GrantingParty::factory(),
            'workplace_address_id' => Address::factory(),
            'student_snapshot' => ['name' => fake()->name(), 'student_year_semester' => '2026/2'],
            'internship_type_snapshot' => ['rules' => ['required_hours' => 300, 'max_daily_hours' => 6, 'max_weekly_hours' => 30]],
            'granting_party_snapshot' => ['name' => fake()->company()],
            'supervisor_snapshot' => ['name' => fake()->name()],
            'weekly_hours' => [
                'sunday' => 0, 'monday' => 4, 'tuesday' => 4, 'wednesday' => 4,
                'thursday' => 4, 'friday' => 4, 'saturday' => 0,
            ],
            'activities' => 'Acompanhar as atividades do setor.',
            'internship_sector' => null,
            'planned_start_date' => now()->addMonth()->toDateString(),
            'projected_end_date' => now()->addMonths(5)->toDateString(),
            'released_at' => null,
            'released_by_affiliation_id' => null,
            'is_remunerated' => false,
            'grant_value' => null,
            'transportation_allowance' => null,
            'protocol_number' => null,
            'observations' => null,
            'supervisor_grade' => null,
            'report_grade' => null,
            'presentation_grade' => null,
            'report_graded_by_affiliation_id' => null,
            'presentation_graded_by_affiliation_id' => null,
            'report_graded_at' => null,
            'presentation_graded_at' => null,
            'consolidated_grade' => null,
            'status' => InternshipStatus::PendingFormalization,
        ];
    }

    public function remunerated(): static
    {
        return $this->state(fn (): array => [
            'is_remunerated' => true,
            'grant_value' => 600,
            'transportation_allowance' => 120,
        ]);
    }

    public function released(): static
    {
        return $this->state(fn (): array => [
            'status' => InternshipStatus::Released,
            'released_at' => now(),
            'released_by_affiliation_id' => Affiliation::factory()->server(),
        ]);
    }
}
