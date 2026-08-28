<?php

namespace App\Enums;

enum EmailDeliveryAttemptStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    /**
     * Obter o rótulo do status da tentativa de entrega de e-mail em português.
     */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Na fila',
            self::Sent => 'Enviada',
            self::Failed => 'Falhou',
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
