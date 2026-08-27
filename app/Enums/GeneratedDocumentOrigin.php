<?php

namespace App\Enums;

enum GeneratedDocumentOrigin: string
{
    case Sge = 'sge';
    case GrantingParty = 'granting_party';

    /**
     * Obter o rótulo da origem do documento gerado em português.
     */
    public function label(): string
    {
        return match ($this) {
            self::Sge => 'SGE',
            self::GrantingParty => 'Parte concedente',
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
