<x-mail::message>
# Sua conta foi criada

Sua conta no Sistema de Gestão de Estágios foi criada com o vínculo **{{ $affiliationName }}**.

Para definir sua senha, abra a página abaixo e solicite um link de recuperação. Enviaremos a mensagem para este endereço de e-mail.

<x-mail::button :url="$requestUrl">
Solicitar link para definir senha
</x-mail::button>

Se você não esperava a criação desta conta, procure a administração do sistema.

@include('emails.partials.signature', ['requesterName' => $requesterName ?? null, 'requesterRole' => $requesterRole ?? null])
</x-mail::message>
