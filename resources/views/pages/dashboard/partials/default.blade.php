<div class="w-full space-y-5">
    <flux:heading size="xl" level="1">Painel</flux:heading>
    <flux:text>Use a navegação para acessar as funcionalidades disponíveis para o vínculo selecionado.</flux:text>
    @can('viewAny', \App\Models\InternshipRequest::class)
        <flux:button variant="primary" :href="route('internship-requests.index')" wire:navigate>Abrir ou acompanhar meu estágio</flux:button>
    @endcan
</div>
