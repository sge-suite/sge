<?php

namespace App\Support;

class UserCsv extends ImportCsv
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'cpf' => 'cpf', 'nome' => 'name', 'email' => 'email', 'tipo_vinculo' => 'type',
        'matricula' => 'registration_number', 'curso' => 'course_id',
    ];

    /** @var list<string> */
    public const array REQUIRED = ['cpf', 'nome', 'email', 'tipo_vinculo', 'matricula', 'curso'];
}
