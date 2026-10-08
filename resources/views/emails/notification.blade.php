<x-mail::message>
# {{ $messageSubject }}

{{ $body }}

<x-mail::button :url="$dashboardUrl">
Acessar o sistema
</x-mail::button>

@include('emails.partials.signature', ['requesterName' => $requesterName ?? null, 'requesterRole' => $requesterRole ?? null])
</x-mail::message>
