<table style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
<tbody>
@foreach ($details as $label => $value)
<tr>
<th scope="row" style="width: 40%; padding: 10px; border-bottom: 1px solid #e4e4e7; text-align: left; vertical-align: top;">{{ $label }}</th>
<td style="padding: 10px; border-bottom: 1px solid #e4e4e7; vertical-align: top; overflow-wrap: anywhere;">{{ $value }}</td>
</tr>
@endforeach
</tbody>
</table>
