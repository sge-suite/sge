<?php

namespace Database\Factories;

use App\Enums\GeneratedDocumentType;
use App\Models\Campus;
use App\Models\DocumentTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentTemplate>
 */
class DocumentTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campus_id' => null,
            'name' => fake()->words(3, true),
            'description' => null,
            'document_type' => GeneratedDocumentType::Main,
            'deactivated_at' => null,
        ];
    }

    public function forCampus(): static
    {
        return $this->state(fn (): array => ['campus_id' => Campus::factory()]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (): array => ['deactivated_at' => now()]);
    }
}
