<?php

namespace App\Enums;

enum GeneratedDocumentType: string
{
    case Main = 'main';
    case Addendum = 'addendum';
    case OrientationCertificate = 'orientation_certificate';

    /**
     * Obter o rótulo do tipo de documento gerado em português.
     */
    public function label(): string
    {
        return match ($this) {
            self::Main => 'Documento principal',
            self::Addendum => 'Aditivo',
            self::OrientationCertificate => 'Atestado de orientação',
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
