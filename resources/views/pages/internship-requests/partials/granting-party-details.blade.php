        <dl class="grid gap-4 text-sm sm:grid-cols-2">
            @foreach (['Concedente' => $party->name, 'CPF/CNPJ' => \App\Helpers\BrazilianDocumentHelper::formatCpfCnpj($party->document_number),
                'Representante legal' => $party->representative_name, 'Cargo do representante' => $party->representative_role,
                'Área de atuação' => $party->field_of_activity, 'Conselho profissional' => $party->professional_council,
                'Registro no conselho' => $party->council_registration_number, 'Processo de credenciamento' => $party->credentialing_process_number, 'Endereço' => $party->address?->street.', '.$party->address?->number.' — '.$party->address?->neighborhood,
                'Cidade/UF' => $party->address?->city?->name.'/'.$party->address?->city?->state?->value, 'CEP' => $party->address?->zip_code,
                'Telefone' => $party->phone, 'E-mail' => $party->email] as $label => $value)
                <div wire:key="party-confirm-{{ $loop->index }}"><dt class="text-zinc-500">{{ $label }}</dt><dd class="mt-1 break-words">{{ filled($value) ? $value : 'Não informado / não se aplica' }}</dd></div>
            @endforeach
        </dl>
