<?php

namespace App\Enums;

enum GeneratedDocumentStatus: string
{
    case Generated = 'generated';
    case AwaitingSignature = 'awaiting_signature';
    case Signed = 'signed';
    case Cancelled = 'cancelled';

    /**
     * Obter o rótulo do status do documento gerado em português.
     */
    public function label(): string
    {
        return match ($this) {
            self::Generated => 'Gerado',
            self::AwaitingSignature => 'Aguardando assinatura',
            self::Signed => 'Assinado',
            self::Cancelled => 'Cancelado',
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
