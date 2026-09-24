<?php

namespace Database\Factories;

use App\Enums\InternshipCancellationRequestStatus;
use App\Models\Internship;
use App\Models\InternshipCancellationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InternshipCancellationRequest> */
class InternshipCancellationRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'internship_id' => Internship::factory(),
            'reason' => 'Não poderei continuar o estágio.',
            'status' => InternshipCancellationRequestStatus::Submitted,
            'reviewed_at' => null,
            'decision_reason' => null,
            'effective_date' => null,
        ];
    }
}
