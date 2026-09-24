<?php

namespace Database\Factories;

use App\Enums\InternshipRequestStatus;
use App\Enums\LegalCapacityDeclaration;
use App\Models\Affiliation;
use App\Models\GrantingParty;
use App\Models\InternshipRequest;
use App\Models\InternshipType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InternshipRequest>
 */
class InternshipRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'affiliation_id' => Affiliation::factory()->student()->create()->id,
            'course_id' => null,
            'internship_type_id' => null,
            'advisor_affiliation_id' => null,
            'granting_party_id' => null,
            'granting_party_registration_request_id' => null,
            'supervisor_affiliation_id' => null,
            'supervisor_registration_request_id' => null,
            'student_year_semester' => null,
            'legal_capacity_declaration' => null,
            'legal_guardian_name' => null,
            'legal_guardian_cpf' => null,
            'legal_guardian_kinship' => null,
            'legal_guardian_email' => null,
            'activities' => null,
            'internship_sector' => null,
            'weekly_hours' => null,
            'planned_start_date' => null,
            'projected_end_date' => null,
            'is_remunerated' => null,
            'grant_value' => null,
            'transportation_allowance' => null,
            'observations' => null,
            'status' => InternshipRequestStatus::Draft,
            'internship_id' => null,
            'terms_accepted_at' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'course_id' => Affiliation::findOrFail($attributes['affiliation_id'])->course_id,
            'internship_type_id' => fn (array $attributes): int => InternshipType::factory()->create([
                'course_id' => $attributes['course_id'],
            ])->id,
            'granting_party_id' => GrantingParty::factory(),
            'supervisor_affiliation_id' => Affiliation::factory()->supervisor(),
            'student_year_semester' => '2026/2',
            'legal_capacity_declaration' => LegalCapacityDeclaration::Minor,
            'legal_guardian_name' => fake()->name(),
            'legal_guardian_cpf' => '529.982.247-25',
            'legal_guardian_kinship' => 'Mãe',
            'legal_guardian_email' => fake()->safeEmail(),
            'activities' => 'Acompanhar as atividades do setor.',
            'internship_sector' => 'Produção',
            'weekly_hours' => [
                'sunday' => 0, 'monday' => 4, 'tuesday' => 4, 'wednesday' => 4,
                'thursday' => 4, 'friday' => 4, 'saturday' => 0,
            ],
            'planned_start_date' => now()->addMonth()->toDateString(),
            'projected_end_date' => now()->addMonths(6)->toDateString(),
            'is_remunerated' => false,
            'status' => InternshipRequestStatus::Submitted,
            'terms_accepted_at' => now(),
        ]);
    }
}
