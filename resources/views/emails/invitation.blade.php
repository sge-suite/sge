<x-email-message subject="Sua conta no SGE foi criada" :recipient-identity="$recipientIdentity ?? null" :requester-name="$requesterName ?? null" :requester-role="$requesterRole ?? null">
Seu acesso ao Sistema de Gestão de Estágios está disponível com o vínculo **{{ $affiliationName }}**.

<x-mail::panel>
**E-mail de acesso:** {{ $loginEmail }}

@if ($affiliationEmail !== null && $affiliationEmail !== $loginEmail)
**E-mail do vínculo:** {{ $affiliationEmail }}
@endif
</x-mail::panel>

@if (! isset($recipientIdentity))
@include('emails.partials.internship-details', ['details' => array_filter(['Vínculo' => $affiliationName, 'Campus' => $campusName, 'Curso' => $courseName, 'Matrícula' => $registrationNumber], fn ($value) => $value !== null)])
@endif

## Primeiro acesso

1. Abra a página abaixo e solicite o link para definir sua senha usando o e-mail de acesso.
2. Abra a mensagem que chegará a esse endereço e escolha sua senha.
3. Entre no SGE e selecione o vínculo indicado nesta mensagem.

<x-mail::button :url="$requestUrl">
Solicitar link para definir senha
</x-mail::button>

Se você não esperava a criação desta conta, procure a administração do sistema.

</x-email-message>
