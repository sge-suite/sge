<?php

namespace App\Enums;

enum InternshipRequestCorrectionStatus: string
{
    case Open = 'open';
    case Responded = 'responded';
    case Resolved = 'resolved';
    case Cancelled = 'cancelled';

    /**
     * Obter o rótulo do status da correção da solicitação de estágio em português.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberta',
            self::Responded => 'Respondida',
            self::Resolved => 'Resolvida',
            self::Cancelled => 'Cancelada',
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
     * Retornar todos os valores disponíveis do enum.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
