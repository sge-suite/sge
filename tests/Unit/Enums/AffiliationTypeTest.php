<?php

use App\Enums\AffiliationType;
use Tests\TestCase;

uses(TestCase::class);

test('provides affiliation type values and labels', function () {
    expect(AffiliationType::values())->toBe([
        'system_administrator',
        'campus_administrator',
        'internship_office',
        'coordinator',
        'advisor',
        'student',
        'supervisor',
        'teaching_direction',
    ])
        ->and(AffiliationType::options())->toBe([
            'system_administrator' => 'Administrador do Sistema',
            'campus_administrator' => 'Administrador do Campus',
            'internship_office' => 'Setor de Estágios',
            'coordinator' => 'Coordenador de Curso',
            'advisor' => 'Orientador',
            'student' => 'Estudante',
            'supervisor' => 'Supervisor',
            'teaching_direction' => 'Direção de Ensino',
        ])
        ->and(AffiliationType::Coordinator->label())->toBe('Coordenador de Curso');
});
