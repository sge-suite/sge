<x-mail::message>
# {{ $messageSubject }}

Olá, {{ $internshipDetails['Discente'] }}.

{{ $body }}

## Identificação do estágio

@include('emails.partials.internship-details', ['details' => $internshipDetails])

## Resultado da avaliação

@include('emails.partials.internship-details', ['details' => $grades])

{{ $explanation }}

<x-mail::button :url="$dashboardUrl">
Acessar o SGE
</x-mail::button>

@include('emails.partials.signature', ['requesterName' => null, 'requesterRole' => null])
</x-mail::message>
