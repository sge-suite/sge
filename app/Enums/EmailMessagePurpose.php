<?php

namespace App\Enums;

enum EmailMessagePurpose: string
{
    case Notification = 'notification';
    case DocumentSignature = 'document_signature';
    case InternshipRequestSubmitted = 'internship_request_submitted';
    case AccountCreated = 'account_created';
    case NewAffiliation = 'new_affiliation';
    case AccountEmailChanged = 'account_email_changed';
    case AdministrativeChange = 'administrative_change';

    /**
     * Obter o rótulo da finalidade da mensagem de e-mail em português.
     */
    public function label(): string
    {
        return match ($this) {
            self::Notification => 'Notificação operacional',
            self::DocumentSignature => 'Documento disponível para assinatura',
            self::InternshipRequestSubmitted => 'Comprovante de envio da solicitação de estágio',
            self::AccountCreated => 'Conta criada',
            self::NewAffiliation => 'Novo vínculo',
            self::AccountEmailChanged => 'Alteração de e-mail da conta',
            self::AdministrativeChange => 'Alteração administrativa',
        };
    }

    /**
     * Retornar opções no formato [valor => rótulo].
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Retornar todos os valores persistidos do enum.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
