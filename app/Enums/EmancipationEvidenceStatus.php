<?php

namespace App\Enums;

enum EmancipationEvidenceStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    /**
     * Obter o rótulo do status da evidência de emancipação em português.
     */
    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Enviada',
            self::UnderReview => 'Em análise',
            self::Approved => 'Aprovada',
            self::Returned => 'Devolvida',
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
     * Retornar todos os valores persistidos do enum.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
