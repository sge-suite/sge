<?php

namespace App\Enums;

enum AffiliationType: string
{
    case SystemAdministrator = 'system_administrator';
    case CampusAdministrator = 'campus_administrator';
    case InternshipOffice = 'internship_office';
    case Coordinator = 'coordinator';
    case Advisor = 'advisor';
    case Student = 'student';
    case Supervisor = 'supervisor';
    case TeachingDirection = 'teaching_direction';

    /**
     * Obter o rótulo do tipo de vínculo em português.
     */
    public function label(): string
    {
        return match ($this) {
            self::SystemAdministrator => 'Administrador do Sistema',
            self::CampusAdministrator => 'Administrador do Campus',
            self::InternshipOffice => 'Setor de Estágios',
            self::Coordinator => 'Coordenador de Curso',
            self::Advisor => 'Orientador',
            self::Student => 'Estudante',
            self::Supervisor => 'Supervisor',
            self::TeachingDirection => 'Direção de Ensino',
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
