<?php

namespace Database\Factories;

use App\Enums\PartyDocumentType;
use App\Models\Address;
use App\Models\GrantingParty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GrantingParty>
 */
class GrantingPartyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_type' => PartyDocumentType::CNPJ,
            'document_number' => '04.252.011/0001-10',
            'name' => fake()->company(),
            'address_id' => Address::factory(),
            'representative_name' => fake()->name(),
            'representative_role' => 'Diretor(a)',
            'phone' => null,
            'email' => null,
            'field_of_activity' => 'Serviços',
            'professional_council' => null,
            'council_registration_number' => null,
            'credentialing_process_number' => null,
        ];
    }

    public function cpf(): static
    {
        return $this->state(fn (): array => [
            'document_type' => PartyDocumentType::CPF,
            'document_number' => '529.982.247-25',
        ]);
    }

    public function cnpj(): static
    {
        return $this->state(fn (): array => [
            'document_type' => PartyDocumentType::CNPJ,
            'document_number' => '04.252.011/0001-10',
        ]);
    }

    public function unit(string $name): static
    {
        return $this->state(fn (): array => ['name' => $name]);
    }
}
