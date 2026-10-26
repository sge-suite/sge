<x-email-message subject="Seu novo vínculo está disponível" :recipient-identity="$recipientIdentity ?? null" :requester-name="$requesterName ?? null" :requester-role="$requesterRole ?? null">
O vínculo **{{ $affiliationName }}** foi adicionado à sua conta no Sistema de Gestão de Estágios.

<x-mail::panel>
Entre com o e-mail e a senha da sua conta e selecione o vínculo identificado acima para trabalhar nesse contexto.
</x-mail::panel>

@if (! isset($recipientIdentity))
@include('emails.partials.internship-details', ['details' => array_filter(['Vínculo' => $affiliationName, 'Campus' => $campusName, 'Matrícula' => $registrationNumber], fn ($value) => $value !== null)])
@endif

<x-mail::button :url="$loginUrl">
Acessar o sistema
</x-mail::button>

Se você não esperava esta alteração, procure a administração do sistema.

</x-email-message>
