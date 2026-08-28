<?php

namespace App\Enums;

enum InternshipStatus: string
{
    case PendingFormalization = 'pending_formalization';
    case AwaitingSignatures = 'awaiting_signatures';
    case PendingCorrection = 'pending_correction';
    case Released = 'released';
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Obter o rótulo do status do estágio em português.
     */
    public function label(): string
    {
        return match ($this) {
            self::PendingFormalization => 'Em formalização',
            self::AwaitingSignatures => 'Aguardando assinaturas',
            self::PendingCorrection => 'Com pendência documental',
            self::Released => 'Liberado',
            self::InProgress => 'Em andamento',
            self::Paused => 'Pausado',
            self::Completed => 'Concluído',
            self::Cancelled => 'Cancelado',
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
     * Retornar todos os valores disponíveis do enum.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
