<table style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
<tbody>
@foreach ($details as $label => $value)
<tr style="{{ ($highlightLabel ?? null) === $label ? 'background-color: #f0fdf4;' : '' }}">
<th scope="row" style="width: 40%; padding: 10px; border-bottom: 1px solid #e4e4e7; text-align: left; vertical-align: top;">{{ $label }}</th>
<td style="padding: 10px; border-bottom: 1px solid #e4e4e7; vertical-align: top; overflow-wrap: anywhere;">@if (($highlightLabel ?? null) === $label)<strong style="color: #14532d;">{{ $value }}</strong>@else{{ $value }}@endif</td>
</tr>
@endforeach
</tbody>
</table>
