<?php

namespace App\Enums;

enum EmailMessagePurpose: string
{
    case PasswordReset = 'password_reset';
    case Notification = 'notification';
    case NewAffiliation = 'new_affiliation';

    /**
     * Obter o rótulo da finalidade da mensagem de e-mail em português.
     */
    public function label(): string
    {
        return match ($this) {
            self::PasswordReset => 'Recuperação de senha',
            self::Notification => 'Notificação operacional',
            self::NewAffiliation => 'Novo vínculo',
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
