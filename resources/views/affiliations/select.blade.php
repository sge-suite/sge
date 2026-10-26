<x-layouts::app title="Selecionar vínculo">
    @php
        $affiliationOptions = $affiliations->mapWithKeys(fn ($affiliation): array => [
            $affiliation->id => $affiliation->contextLabel().($currentAffiliation?->id === $affiliation->id ? ' · Em uso' : ''),
        ])->all();
    @endphp

    <section class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-6">
        <div>
            <flux:heading size="xl">Selecionar vínculo</flux:heading>
            <flux:text>Escolha o contexto em que deseja atuar. Confira o campus, o curso e a matrícula quando informados; o e-mail é o deste vínculo.</flux:text>
        </div>

        <form method="POST" action="{{ route('affiliations.store') }}" class="flex flex-col gap-4">
            @csrf
            <x-select
                name="affiliation_id"
                label="Vínculo para acessar"
                :value="old('affiliation_id', $currentAffiliation?->id ?? '')"
                :options="$affiliationOptions"
                placeholder="Selecione um vínculo"
                searchable
                open-on-mount
                required
            />
            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" class="w-full sm:w-auto">Continuar</flux:button>
            </div>
        </form>
    </section>
</x-layouts::app>
