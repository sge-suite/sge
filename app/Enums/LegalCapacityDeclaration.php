<?php

namespace App\Enums;

enum LegalCapacityDeclaration: string
{
    case Adult = 'adult';
    case Minor = 'minor';
    case EmancipatedMinor = 'emancipated_minor';

    /**
     * Obter o rótulo da declaração de capacidade civil em português.
     */
    public function label(): string
    {
        return match ($this) {
            self::Adult => 'Maior de idade',
            self::Minor => 'Menor de idade',
            self::EmancipatedMinor => 'Menor emancipado',
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
