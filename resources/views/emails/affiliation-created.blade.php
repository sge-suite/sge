<x-mail::message>
# Novo vínculo criado

Um vínculo foi criado para sua conta no Sistema de Gestão de Estágios. Agora você pode acessar o sistema com este vínculo.

<x-mail::button :url="$loginUrl">
Acessar o sistema
</x-mail::button>

Se você não esperava esta alteração, procure a administração do sistema.

Atenciosamente,<br>
{{ config('app.name') }}
</x-mail::message>
