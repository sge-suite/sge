<x-mail::message>
# E-mail da conta alterado

O endereço usado para entrar no Sistema de Gestão de Estágios foi alterado.

<x-mail::panel>
**E-mail anterior:** {{ $previousEmail }}

**Novo e-mail:** {{ $newEmail }}
</x-mail::panel>

Se você reconhece essa alteração, use o novo endereço no próximo acesso. Se não reconhece, procure imediatamente a administração do sistema.

<x-mail::button :url="$loginUrl">
Acessar o sistema
</x-mail::button>

Atenciosamente,<br>
{{ config('app.name') }}
</x-mail::message>
