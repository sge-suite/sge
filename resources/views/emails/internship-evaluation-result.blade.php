<x-email-message :subject="$messageSubject" :recipient-identity="$recipientIdentity">
{{ $body }}

## Resultado da avaliação

@include('emails.partials.internship-details', ['details' => $grades, 'highlightLabel' => array_key_exists('Nota final', $grades) ? 'Nota final' : 'Nota do supervisor'])

<x-mail::panel>
{{ $explanation }}
</x-mail::panel>

## Identificação do estágio

@include('emails.partials.internship-details', ['details' => $internshipDetails])

<x-mail::button :url="$dashboardUrl">
Acessar o SGE
</x-mail::button>

</x-email-message>
