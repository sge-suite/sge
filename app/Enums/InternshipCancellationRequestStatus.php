<?php

namespace App\Enums;

enum InternshipCancellationRequestStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    /**
     * Obter o rótulo do status da solicitação de cancelamento de estágio em português.
     */
    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Enviada',
            self::UnderReview => 'Em análise',
            self::Approved => 'Aprovada',
            self::Rejected => 'Recusada',
            self::Withdrawn => 'Retirada pelo discente',
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
