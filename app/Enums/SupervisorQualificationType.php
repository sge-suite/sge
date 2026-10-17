<?php

namespace App\Enums;

enum SupervisorQualificationType: string
{
    case Academic = 'academic';
    case Professional = 'professional';

    public function label(): string
    {
        return match ($this) {
            self::Academic => 'Formação acadêmica',
            self::Professional => 'Experiência profissional',
        };
    }
}
