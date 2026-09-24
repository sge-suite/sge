<?php

namespace Database\Factories;

use App\Enums\InternshipRequestCorrectionStatus;
use App\Models\InternshipRequest;
use App\Models\InternshipRequestCorrection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InternshipRequestCorrection> */
class InternshipRequestCorrectionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'internship_request_id' => InternshipRequest::factory()->submitted(),
            'message' => 'Corrija a jornada semanal informada.',
            'affected_sections' => ['schedule'],
            'status' => InternshipRequestCorrectionStatus::Open,
            'responded_at' => null,
            'resolved_at' => null,
        ];
    }
}
