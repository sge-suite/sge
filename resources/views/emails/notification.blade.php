<x-email-message :subject="$messageSubject" :recipient-identity="$recipientIdentity ?? null" :requester-name="$requesterName ?? null" :requester-role="$requesterRole ?? null">
{{ $body }}

@if (! empty($internshipDetails))
## Identificação do estágio

@include('emails.partials.internship-details', ['details' => $internshipDetails])

@endif
@if (! empty($nextStep))
<x-mail::panel>
{{ $nextStep }}
</x-mail::panel>
@endif

<x-mail::button :url="$dashboardUrl">
{{ $actionLabel ?? 'Acessar o SGE' }}
</x-mail::button>
</x-email-message>
