<?php

namespace App\Support;

class UserCsv extends ImportCsv
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'cpf' => 'cpf', 'nome' => 'name', 'email_login' => 'login_email',
        'tipo_vinculo' => 'type', 'email_vinculo' => 'email',
        'matricula' => 'registration_number', 'curso' => 'course_id',
    ];

    /** @var list<string> */
    public const array REQUIRED = ['cpf', 'nome', 'email_login', 'tipo_vinculo', 'email_vinculo', 'matricula', 'curso'];
}
