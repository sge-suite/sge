<?php

namespace App\Enums;

enum EvaluationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    /**
     * Obter o rótulo do status da avaliação em português.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Submitted => 'Enviada',
            self::Returned => 'Devolvida',
            self::Approved => 'Aprovada',
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
