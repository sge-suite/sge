<?php

namespace App\Support;

class GrantingPartyCsv extends ImportCsv
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'tipo_documento' => 'document_type', 'documento' => 'document_number', 'nome' => 'name',
        'representante_nome' => 'representative_name', 'representante_cargo' => 'representative_role',
        'atividade' => 'field_of_activity', 'telefone' => 'phone', 'email' => 'email',
        'conselho_profissional' => 'professional_council', 'registro_conselho' => 'council_registration_number',
        'processo_credenciamento' => 'credentialing_process_number', 'logradouro' => 'address.street',
        'numero' => 'address.number', 'bairro' => 'address.neighborhood', 'cep' => 'address.zip_code',
        'cidade' => 'city', 'uf' => 'state',
    ];

    /** @var list<string> */
    public const array REQUIRED = [
        'tipo_documento', 'documento', 'nome', 'representante_nome', 'representante_cargo',
        'atividade', 'logradouro', 'numero', 'bairro', 'cep', 'cidade', 'uf',
    ];
}
