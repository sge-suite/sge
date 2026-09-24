<?php

namespace Database\Factories;

use App\Enums\BrazilianState;
use App\Enums\PartyDocumentType;
use App\Enums\RegistrationRequestStatus;
use App\Models\GrantingPartyRegistrationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GrantingPartyRegistrationRequest>
 */
class GrantingPartyRegistrationRequestFactory extends Factory
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
            'street' => 'Rua das Flores',
            'number' => '123',
            'neighborhood' => 'Centro',
            'city' => 'Santa Maria',
            'uf' => BrazilianState::RioGrandeDoSul,
            'zip_code' => '97010-100',
            'representative_name' => fake()->name(),
            'representative_role' => 'Diretor(a)',
            'phone' => null,
            'email' => null,
            'field_of_activity' => 'Serviços',
            'professional_council' => null,
            'council_registration_number' => null,
            'credentialing_process_number' => null,
            'status' => RegistrationRequestStatus::Draft,
            'granting_party_id' => null,
            'reviewed_at' => null,
            'decision_reason' => null,
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
}
