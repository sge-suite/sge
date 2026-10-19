<?php

namespace Database\Factories;

use App\Models\Affiliation;
use App\Models\DocumentTemplate;
use App\Models\TemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TemplateVersion>
 */
class TemplateVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_template_id' => DocumentTemplate::factory(),
            'version' => 1,
            'file_sha256' => hash('sha256', fake()->uuid()),
            'file_size' => fake()->numberBetween(1, 10_000),
            'detected_variables' => [],
            'validation_report' => ['status' => 'validated', 'errors' => [], 'warnings' => []],
            'uploaded_by_affiliation_id' => Affiliation::factory()->server(),
        ];
    }
}
