<x-email-message subject="Seu e-mail de acesso foi alterado" :recipient-identity="$recipientIdentity" :requester-name="$requesterName ?? null" :requester-role="$requesterRole ?? null">
O endereço usado para entrar no Sistema de Gestão de Estágios foi alterado.

@if ($isSelfChange ?? false)
Esta alteração foi realizada por você, na sua própria conta.
@endif

@include('emails.partials.internship-details', ['details' => ['E-mail anterior' => $previousEmail, 'Novo e-mail de acesso' => $newEmail]])

Se você reconhece essa alteração, use o novo endereço no próximo acesso. Se não reconhece, procure imediatamente a administração do sistema.

<x-mail::button :url="$loginUrl">
Acessar o sistema
</x-mail::button>

</x-email-message>
