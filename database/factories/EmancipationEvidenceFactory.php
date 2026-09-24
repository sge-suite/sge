<?php

namespace Database\Factories;

use App\Enums\EmancipationEvidenceStatus;
use App\Models\EmancipationEvidence;
use App\Models\InternshipRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmancipationEvidence> */
class EmancipationEvidenceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'internship_request_id' => InternshipRequest::factory(),
            'status' => EmancipationEvidenceStatus::Submitted,
            'reviewed_at' => null,
            'return_reason' => null,
        ];
    }
}
