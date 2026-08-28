<?php

namespace App\Enums;

enum RegistrationRequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * Obter o rótulo do status da solicitação de cadastro em português.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Submitted => 'Enviada',
            self::UnderReview => 'Em análise',
            self::Approved => 'Aprovada',
            self::Rejected => 'Recusada',
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
