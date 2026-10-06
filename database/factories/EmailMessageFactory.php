<?php

namespace Database\Factories;

use App\Enums\EmailMessagePurpose;
use App\Models\EmailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmailMessage>
 */
class EmailMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_id' => null,
            'purpose' => EmailMessagePurpose::Notification,
            'subject' => 'Documento disponível',
            'content_text' => 'Um documento está disponível para análise.',
            'content_html' => '<p>Um documento está disponível para análise.</p>',
            'template_key' => 'internship.document-available',
            'template_version' => '1',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function operational(DatabaseNotification $notification): static
    {
        return $this->state(fn (): array => [
            'notification_id' => $notification->id,
        ]);
    }

    public function newAffiliation(): static
    {
        return $this->state(fn (): array => [
            'purpose' => EmailMessagePurpose::NewAffiliation,
            'subject' => 'Novo vínculo criado no Sistema de Gestão de Estágios',
            'content_text' => 'Tipo: Administrador do Sistema. Campus: Escopo global. Registro institucional: ADM-001.',
            'content_html' => '<p>Tipo: Administrador do Sistema. Campus: Escopo global. Registro institucional: ADM-001.</p>',
            'template_key' => 'administrative.affiliation-created',
            'template_version' => '1',
        ]);
    }
}
