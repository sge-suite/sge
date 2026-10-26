<x-email-message :subject="$messageSubject" :recipient-identity="$recipientIdentity" :requester-name="$requesterName ?? null" :requester-role="$requesterRole ?? null">
Sua assinatura é necessária no documento **{{ $documentName }}**.

<x-mail::panel>
**Onde assinar:** {{ $location }}

Confira o documento e siga as orientações de assinatura do local informado.
</x-mail::panel>

## Identificação do estágio

@include('emails.partials.internship-details', ['details' => $internshipDetails])

@if ($protocolNumber !== null)
**Número de protocolo:** {{ $protocolNumber }}

@endif

@if ($personalMessage !== '')
**Orientação do Setor de Estágio:**

{{ $personalMessage }}
@endif

@if ($dashboardUrl)
<x-mail::button :url="$dashboardUrl">
Consultar no SGE
</x-mail::button>
@endif

</x-email-message>
