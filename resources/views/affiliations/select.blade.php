<x-layouts::app title="Selecionar vínculo">
    <section class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-6">
        <div>
            <flux:heading size="xl">Selecionar vínculo</flux:heading>
            <flux:text>Escolha o contexto em que deseja atuar.</flux:text>
        </div>

        <div class="flex flex-col gap-3">
            @foreach ($affiliations as $affiliation)
                <form method="POST" action="{{ route('affiliations.store') }}" wire:key="affiliation-{{ $affiliation->id }}">
                    @csrf
                    <input type="hidden" name="affiliation_id" value="{{ $affiliation->id }}">
                    <flux:button type="submit" class="w-full justify-start" :variant="$currentAffiliation?->is($affiliation) ? 'primary' : 'outline'">
                        {{ $affiliation->type->label() }} · {{ $affiliation->campus?->name ?? 'Escopo global' }}
                    </flux:button>
                </form>
            @endforeach
        </div>
    </section>
</x-layouts::app>
