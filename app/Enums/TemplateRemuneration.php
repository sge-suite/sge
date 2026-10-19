<?php

namespace App\Enums;

enum TemplateRemuneration: string
{
    case Any = 'any';
    case Paid = 'paid';
    case Unpaid = 'unpaid';

    public function label(): string
    {
        return match ($this) {
            self::Any => 'Independente da remuneração',
            self::Paid => 'Com remuneração',
            self::Unpaid => 'Sem remuneração',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_column(array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases()), 'label', 'value');
    }
}
