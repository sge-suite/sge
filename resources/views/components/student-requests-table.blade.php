@props(['requests', 'canEdit' => false, 'paginated' => false])

<flux:table :paginate="$paginated ? $requests : null">
    <flux:table.columns>
        <flux:table.column>Solicitação / Tipo</flux:table.column>
        <flux:table.column class="hidden md:table-cell">Concedente</flux:table.column>
        <flux:table.column>Situação</flux:table.column>
        <flux:table.column class="hidden lg:table-cell">Atualizada em</flux:table.column>
    </flux:table.columns>
    <flux:table.rows>
        @foreach ($requests as $request)
            @php
                $status = $request->status->value;
                $editable = $canEdit && in_array($status, ['draft', 'pending_correction'], true);
                $color = match ($status) { 'pending_correction' => 'orange', 'submitted', 'under_review' => 'amber', 'accepted' => 'green', 'rejected' => 'red', default => 'zinc' };
                $party = $request->granting_party_snapshot['name'] ?? $request->grantingParty?->name ?? 'Não informada';
            @endphp
            <flux:table.row :key="'student-request-'.$request->id" class="group cursor-pointer transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-student-request-link]').click()">
                <flux:table.cell class="whitespace-normal">
                    <a data-student-request-link href="{{ route('internship-requests.show', $request) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">Solicitação nº {{ $request->id }}</a>
                    <flux:text size="sm">{{ $request->internship_type_snapshot['name'] ?? $request->internshipType?->name ?? 'Tipo ainda não escolhido' }}</flux:text>
                    <flux:text size="sm" class="md:hidden">Concedente: {{ $party }}</flux:text>
                    <flux:text size="sm" class="lg:hidden">Atualizada: {{ formatDateTime($request->updated_at) }}</flux:text>
                    @if ($editable)<flux:text size="sm" class="md:hidden">{{ $status === 'pending_correction' ? 'Abra para revisar as pendências.' : 'Abra para continuar o preenchimento.' }}</flux:text>@endif
                </flux:table.cell>
                <flux:table.cell class="hidden whitespace-normal md:table-cell">{{ $party }}</flux:table.cell>
                <flux:table.cell><flux:badge size="sm" :color="$color">{{ $request->status->label() }}</flux:badge></flux:table.cell>
                <flux:table.cell class="hidden lg:table-cell">{{ formatDateTime($request->updated_at) }}</flux:table.cell>
            </flux:table.row>
        @endforeach
    </flux:table.rows>
</flux:table>
