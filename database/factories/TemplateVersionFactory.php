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
            'required_variables' => [],
            'optional_variables' => [],
            'detected_variables' => null,
            'validation_report' => null,
            'uploaded_by_affiliation_id' => Affiliation::factory()->server(),
            'validated_at' => null,
            'validated_by_affiliation_id' => null,
        ];
    }

    public function validated(): static
    {
        return $this->state(fn (): array => [
            'validated_at' => now(),
            'validated_by_affiliation_id' => Affiliation::factory()->server(),
        ]);
    }
}
