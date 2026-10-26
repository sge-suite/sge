<div class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><flux:heading size="xl" level="1">Minhas avaliações</flux:heading><flux:text class="mt-2">Confira quem aguarda sua avaliação e acompanhe o que já enviou ao Setor.</flux:text></div>
        <flux:button :href="route('supervisor-evaluations.index')" icon="clipboard-document-list" wire:navigate>Ver todas ({{ $overview['total'] }})</flux:button>
    </div>
    <flux:text>Há {{ $overview['finished'] }} avaliações aprovadas ou canceladas no histórico. Avaliações enviadas aguardam a análise do Setor.</flux:text>
    @foreach ([
        'returned' => ['title' => 'Devolvidas para correção', 'description' => 'Abra a avaliação e confira a orientação do Setor antes de reenviar.', 'color' => 'orange'],
        'draft' => ['title' => 'Para preencher', 'description' => 'Avaliações solicitadas pelo Setor que ainda não foram enviadas.', 'color' => 'zinc'],
        'submitted' => ['title' => 'Enviadas ao Setor', 'description' => 'Acompanhe suas respostas enquanto aguarda a análise.', 'color' => 'amber'],
    ] as $status => $section)
        <section wire:key="supervisor-dashboard-{{ $status }}" aria-labelledby="supervisor-{{ $status }}" class="space-y-3">
            <div class="flex flex-wrap items-center gap-3"><flux:heading id="supervisor-{{ $status }}" size="lg" level="2">{{ $section['title'] }}</flux:heading><flux:badge :color="$section['color']">{{ $overview['groups'][$status]['count'] }}</flux:badge></div>
            <flux:text>{{ $section['description'] }} Até cinco registros, começando pelos atualizados há mais tempo.</flux:text>
            <div class="grid min-w-0 gap-3 xl:grid-cols-2">
                @forelse ($overview['groups'][$status]['evaluations'] as $evaluation)
                    @php($canEdit = auth()->user()->can('update', $evaluation))
                    <flux:card wire:key="dashboard-evaluation-{{ $evaluation->id }}" class="min-w-0 space-y-3">
                        <div class="space-y-1">
                            <flux:heading class="wrap-anywhere">{{ $evaluation->internship->student_snapshot['name'] ?? 'Discente' }}</flux:heading>
                            <flux:text size="sm">Matrícula {{ $evaluation->internship->student_snapshot['registration_number'] ?? 'não informada' }}</flux:text>
                            <flux:text class="wrap-anywhere">{{ $evaluation->internship->internship_type_snapshot['name'] ?? 'Tipo não informado' }} · Estágio nº {{ $evaluation->internship_id }}</flux:text>
                            <flux:text size="sm" class="wrap-anywhere">{{ $evaluation->internship->course->name }} · {{ $evaluation->internship->granting_party_snapshot['name'] ?? 'Concedente não informada' }}</flux:text>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <flux:text size="sm">Avaliação nº {{ $evaluation->id }} · Atualizada {{ formatDateTime($evaluation->updated_at) }}</flux:text>
                            <flux:button :href="route('supervisor-evaluations.show', $evaluation)" :variant="$canEdit ? 'primary' : 'outline'" wire:navigate>{{ $canEdit ? 'Continuar avaliação' : 'Consultar avaliação' }}</flux:button>
                        </div>
                    </flux:card>
                @empty
                    <flux:text class="rounded-xl border border-dashed border-zinc-300 p-5 dark:border-zinc-700">Nenhuma avaliação nesta situação para o vínculo selecionado.</flux:text>
                @endforelse
            </div>
        </section>
    @endforeach
</div>
