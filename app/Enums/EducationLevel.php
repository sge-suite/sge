<?php

namespace App\Enums;

enum EducationLevel: string
{
    case SecondaryTechnical = 'secondary_technical';
    case Undergraduate = 'undergraduate';
    case Specialization = 'specialization';
    case Masters = 'masters';
    case Doctorate = 'doctorate';

    public function label(): string
    {
        return match ($this) {
            self::SecondaryTechnical => 'Médio/Técnico',
            self::Undergraduate => 'Graduação',
            self::Specialization => 'Especialização',
            self::Masters => 'Mestrado',
            self::Doctorate => 'Doutorado',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
