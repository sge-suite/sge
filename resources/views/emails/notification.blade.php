<x-mail::message>
# {{ $messageSubject }}

{{ $body }}

@if (! empty($internshipDetails))
@include('emails.partials.internship-details', ['details' => $internshipDetails])

@endif
<x-mail::button :url="$dashboardUrl">
Acessar o sistema
</x-mail::button>

@include('emails.partials.signature', ['requesterName' => $requesterName ?? null, 'requesterRole' => $requesterRole ?? null])
</x-mail::message>
