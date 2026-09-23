<?php

namespace App\Enums;

enum EvaluationConcept: string
{
    case Excellent = 'excellent';
    case VeryGood = 'very_good';
    case Good = 'good';
    case Satisfactory = 'satisfactory';
    case Unsatisfactory = 'unsatisfactory';

    public function label(): string
    {
        return match ($this) {
            self::Excellent => 'Ótimo',
            self::VeryGood => 'Muito bom',
            self::Good => 'Bom',
            self::Satisfactory => 'Satisfatório',
            self::Unsatisfactory => 'Insatisfatório',
        };
    }

    public function internshipTypeValueAttribute(): ?string
    {
        return match ($this) {
            self::Excellent => null,
            self::VeryGood => 'very_good_value',
            self::Good => 'good_value',
            self::Satisfactory => 'satisfactory_value',
            self::Unsatisfactory => 'unsatisfactory_value',
        };
    }

    /**
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
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
