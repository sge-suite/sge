<x-mail::message>
# {{ $messageSubject }}

Olá, {{ $recipientName }}.

Como **{{ $recipientRole }}**, solicitamos sua assinatura no documento **{{ $documentName }}**.

@foreach ($internshipDetails as $label => $value)
**{{ $label }}:** {{ $value }}

@endforeach
@if ($protocolNumber !== null)
**Número de protocolo:** {{ $protocolNumber }}

@endif

**Local de disponibilização para assinatura:** {{ $location }}

@if ($personalMessage !== '')
**Orientação do Setor de Estágio:**

{{ $personalMessage }}
@endif

@if ($dashboardUrl)
<x-mail::button :url="$dashboardUrl">
Acessar o SGE
</x-mail::button>
@endif

@include('emails.partials.signature')
</x-mail::message>
