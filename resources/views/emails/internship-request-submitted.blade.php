<x-mail::message>
# {{ $messageSubject }}

Recebemos sua solicitação de estágio para análise. Confira abaixo a cópia dos dados registrados neste envio.

@foreach ($sections as $section)
## {{ $section['title'] }}

<table role="presentation" style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
@foreach ($section['fields'] as $label => $value)
<tr>
<th scope="row" style="width: 40%; padding: 8px; border-bottom: 1px solid #e4e4e7; text-align: left; vertical-align: top;">{{ $label }}</th>
<td style="padding: 8px; border-bottom: 1px solid #e4e4e7; vertical-align: top; overflow-wrap: anywhere; white-space: pre-line;">{{ $value }}</td>
</tr>
@endforeach
</table>

@endforeach
Acompanhe a análise e eventuais orientações do Setor de Estágio pelo SGE.

<x-mail::button :url="$requestUrl">
Acompanhar solicitação
</x-mail::button>

@include('emails.partials.signature', ['requesterName' => null, 'requesterRole' => null])
</x-mail::message>
