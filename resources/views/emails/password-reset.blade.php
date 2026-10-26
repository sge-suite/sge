<x-email-message subject="Defina uma nova senha para sua conta" :recipient-identity="$recipientIdentity">
Recebemos uma solicitação para definir ou redefinir a senha da sua conta no Sistema de Gestão de Estágios.

<x-mail::panel>
O link abaixo é válido por **{{ $expiresInMinutes }} minutos**. A senha é da sua conta de acesso e é usada em todos os vínculos associados a ela.
</x-mail::panel>

<x-mail::button :url="$resetUrl">
Definir nova senha
</x-mail::button>

Se você não fez esta solicitação, ignore esta mensagem. Sua senha só será alterada depois que você abrir o link e concluir a redefinição.

Se o botão não abrir, copie e cole este endereço no navegador:

{{ $resetUrl }}
</x-email-message>
