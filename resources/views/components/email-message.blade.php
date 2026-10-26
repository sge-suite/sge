@props(['subject', 'recipientIdentity' => null, 'requesterName' => null, 'requesterRole' => null])

<x-mail::message>
<div style="border-top: 4px solid #166534; padding-top: 24px; margin-bottom: 24px;">
<h1 style="font-size: 24px; line-height: 1.3; color: #14532d; margin-bottom: 0;">{{ $subject }}</h1>
</div>

@if ($recipientIdentity !== null)
<p>Olá, <strong>{{ $recipientIdentity['name'] }}</strong>.</p>

<table role="presentation" style="width: 100%; margin: 0 0 24px; border-collapse: collapse; background-color: #f0fdf4; border-left: 4px solid #166534;">
<tr><td style="padding: 16px 18px;">
<p style="font-size: 12px; color: #3f6212; margin: 0 0 6px;">DESTINATÁRIO DESTA MENSAGEM</p>
<p style="font-size: 16px; color: #14532d; margin: 0 0 6px;"><strong>{{ $recipientIdentity['role'] }}</strong></p>
@foreach ($recipientIdentity['context'] as $context)
<p style="font-size: 13px; color: #374151; margin: 0 0 4px;">{{ $context }}</p>
@endforeach
</td></tr>
</table>
@endif

{{ $slot }}

<div style="border-top: 1px solid #e4e4e7; padding-top: 20px; margin-top: 28px;">
@include('emails.partials.signature')
</div>
</x-mail::message>
