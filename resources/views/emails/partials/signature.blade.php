Atenciosamente,<br>
@if ($requesterName)
<strong>{{ $requesterName }}</strong><br>
{{ $requesterRole }}<br>
via {{ config('app.name') }}
@else
{{ config('app.name') }}
@endif
