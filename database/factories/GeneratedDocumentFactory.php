<?php

namespace Database\Factories;

use App\Enums\GeneratedDocumentOrigin;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Models\GeneratedDocument;
use App\Models\Internship;
use App\Models\TemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GeneratedDocument> */
class GeneratedDocumentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'internship_id' => Internship::factory(),
            'template_version_id' => TemplateVersion::factory()->validated(),
            'origin' => GeneratedDocumentOrigin::SGE,
            'type' => GeneratedDocumentType::Main,
            'status' => GeneratedDocumentStatus::Generated,
            'signature_availability_location' => null,
            'snapshot' => ['student' => ['name' => fake()->name()]],
            'generation_token' => fake()->uuid(),
            'output_filename' => 'documento.docx',
            'template_sha256' => fn (array $attributes): string => TemplateVersion::findOrFail($attributes['template_version_id'])->file_sha256,
            'generated_at' => now(),
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ];
    }

    public function fromGrantingParty(): static
    {
        return $this->state(fn (): array => [
            'template_version_id' => null,
            'origin' => GeneratedDocumentOrigin::GrantingParty,
            'snapshot' => null,
            'output_filename' => null,
            'template_sha256' => null,
            'generated_at' => null,
        ]);
    }
}
