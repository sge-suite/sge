<x-mail::message>
# {{ $messageSubject }}

{{ $body }}

<x-mail::button :url="$dashboardUrl">
Acessar o sistema
</x-mail::button>

Atenciosamente,<br>
{{ config('app.name') }}
</x-mail::message>
