<?php

namespace Database\Factories;

use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Models\GeneratedDocument;
use App\Models\Internship;
use App\Models\InternshipWorkSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InternshipWorkSchedule> */
class InternshipWorkScheduleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'internship_id' => Internship::factory(),
            'starts_on' => now()->addMonths(2)->toDateString(),
            'ends_on' => null,
            'weekly_hours' => [
                'sunday' => 0, 'monday' => 4, 'tuesday' => 4, 'wednesday' => 4,
                'thursday' => 4, 'friday' => 4, 'saturday' => 0,
            ],
            'generated_document_id' => fn (array $attributes): int => GeneratedDocument::factory()->create([
                'internship_id' => $attributes['internship_id'],
                'type' => GeneratedDocumentType::Addendum,
                'status' => GeneratedDocumentStatus::Signed,
            ])->id,
        ];
    }
}
