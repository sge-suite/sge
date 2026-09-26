<?php

namespace App\Enums;

enum EmailMessagePurpose: string
{
    case Notification = 'notification';
    case AccountCreated = 'account_created';
    case NewAffiliation = 'new_affiliation';
    case AccountEmailChanged = 'account_email_changed';

    /**
     * Obter o rótulo da finalidade da mensagem de e-mail em português.
     */
    public function label(): string
    {
        return match ($this) {
            self::Notification => 'Notificação operacional',
            self::AccountCreated => 'Conta criada',
            self::NewAffiliation => 'Novo vínculo',
            self::AccountEmailChanged => 'Alteração de e-mail da conta',
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
