<x-mail::message>
# Sua conta foi criada

Sua conta no Sistema de Gestão de Estágios foi criada com o vínculo **{{ $affiliationName }}**.

<x-mail::panel>
**E-mail de acesso:** {{ $loginEmail }}

**Tipo de vínculo:** {{ $affiliationName }}

@if ($campusName !== null)
**Campus:** {{ $campusName }}
@endif

@if ($registrationNumber !== null)
**Matrícula:** {{ $registrationNumber }}
@endif

@if ($courseName !== null)
**Curso:** {{ $courseName }}
@endif

@if ($affiliationEmail !== null)
**E-mail do vínculo:** {{ $affiliationEmail }}
@endif
</x-mail::panel>

Para definir sua senha, abra a página abaixo e solicite um link de recuperação. Enviaremos a mensagem para este endereço de e-mail.

<x-mail::button :url="$requestUrl">
Solicitar link para definir senha
</x-mail::button>

Se você não esperava a criação desta conta, procure a administração do sistema.

@include('emails.partials.signature', ['requesterName' => $requesterName ?? null, 'requesterRole' => $requesterRole ?? null])
</x-mail::message>
