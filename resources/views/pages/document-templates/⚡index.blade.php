<?php

use App\Models\DocumentTemplate;
use App\Enums\GeneratedDocumentType;
use App\Enums\TemplateRemuneration;
use App\Support\ActiveAffiliationContext;
use App\Support\PageSize;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Templates DOCX')] class extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $documentType = '';

    #[Url(except: '')]
    public string $remuneration = '';

    public function boot(): void
    {
        Gate::authorize('viewAny', DocumentTemplate::class);
    }

    #[Url(except: 'all')]
    public string $status = 'all';

    public function clearFilters(): void
    {
        $this->reset('search', 'documentType', 'remuneration', 'status');
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'documentType', 'remuneration', 'status'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function templates(): LengthAwarePaginator
    {
        Gate::authorize('viewAny', DocumentTemplate::class);
        $office = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'));
        $type = GeneratedDocumentType::tryFrom($this->documentType);
        $remuneration = TemplateRemuneration::tryFrom($this->remuneration);
        $search = mb_substr(trim($this->search), 0, 255);

        return DocumentTemplate::query()->availableToCampus($office->campus_id)
            ->with('currentVersion')->withCount('templateVersions')
            ->when($this->status === 'active', fn (Builder $query) => $query->whereNull('deactivated_at'))
            ->when($this->status === 'inactive', fn (Builder $query) => $query->whereNotNull('deactivated_at'))
            ->when($remuneration !== null, fn (Builder $query) => $query->where('remuneration', $remuneration))
            ->when($type !== null, fn (Builder $query) => $query->where('document_type', $type))
            ->when($search !== '', fn (Builder $query) => $query->whereLike('name', '%'.addcslashes($search, '%_').'%', caseSensitive: false))
            ->orderBy('name')->orderBy('id')->paginate(PageSize::PER_PAGE);
    }
}; ?>

<div class="w-full space-y-8">
    @if (session('status'))
        <div hidden x-data x-init="$nextTick(() => $flux.toast(@js(session('status')), { variant: 'success' }))"></div>
    @endif
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Templates DOCX</flux:heading>
            <flux:text class="mt-2">Gerencie os modelos do seu campus.</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <flux:button variant="outline" :href="route('document-templates.guide')" wire:navigate>Guia de variáveis</flux:button>
            @can('create', DocumentTemplate::class)
                <flux:button variant="primary" icon="plus" :href="route('document-templates.create')" wire:navigate>Cadastrar template</flux:button>
            @else
                <flux:badge>Campus somente para leitura</flux:badge>
            @endcan
        </div>
    </div>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5 sm:items-end">
        <flux:input label="Buscar por nome" icon="magnifying-glass" type="search" wire:model.live.debounce.300ms="search" maxlength="255" />
        <x-select label="Tipo documental" wire:model.live="documentType" :value="$documentType" :options="['' => 'Todos os tipos'] + GeneratedDocumentType::options()" />
        <x-select label="Remuneração" wire:model.live="remuneration" :value="$remuneration" :options="['' => 'Todas as classificações'] + TemplateRemuneration::options()" />
        <x-select label="Situação" wire:model.live="status" :value="$status" :options="['all' => 'Todos', 'active' => 'Ativos', 'inactive' => 'Desativados']" />
        <div>
            @if ($search !== '' || $documentType !== '' || $remuneration !== '' || $status !== 'all')
                <flux:button class="w-full" variant="outline" wire:click="clearFilters">Limpar filtros</flux:button>
            @else
                <div aria-hidden="true" class="h-10"></div>
            @endif
        </div>
    </div>
    @php($templates = $this->templates)
    <x-loading-overlay target="search,documentType,remuneration,status,clearFilters,gotoPage,nextPage,previousPage,resetPage" label="Atualizando lista…" :dim-while-loading="true">
        @if ($templates->isNotEmpty())
            <flux:table :paginate="$templates" container:class="[&>ui-table-scroll-area]:max-h-[70vh]">
                <flux:table.columns sticky class="bg-white dark:bg-zinc-800">
                    <flux:table.column>Template</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">Tipo documental</flux:table.column>
                    <flux:table.column>Versão vigente</flux:table.column>
                    <flux:table.column>Situação</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($templates as $template)
                        <flux:table.row :key="$template->id" class="group cursor-pointer transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-700" x-on:click="if (! $event.target.closest('a, button')) $el.querySelector('[data-template-link]').click()">
                            <flux:table.cell class="whitespace-normal">
                                <a data-template-link href="{{ route('document-templates.show', $template) }}" wire:navigate class="font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white">{{ $template->name }}</a>
                                <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $template->template_versions_count }} versões · {{ $template->remuneration->label() }}</div>
                                <flux:text size="sm" class="md:hidden">Tipo documental: {{ $template->document_type->label() }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell class="hidden whitespace-normal md:table-cell">{{ $template->document_type->label() }}</flux:table.cell>
                            <flux:table.cell>{{ $template->currentVersion ? 'v'.$template->currentVersion->version : 'Não disponível' }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$template->deactivated_at === null ? 'green' : 'zinc'">{{ $template->deactivated_at === null ? 'Ativo' : 'Desativado' }}</flux:badge></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
                <flux:icon.document class="mx-auto mb-4 size-8 text-zinc-400" />
                @if ($templates->total() > 0)
                    <flux:heading size="lg">Nenhum template nesta página</flux:heading>
                    <flux:button class="mt-5" variant="ghost" wire:click="resetPage">Ir para a primeira página</flux:button>
                @elseif ($search !== '' || $documentType !== '' || $remuneration !== '' || $status !== 'all')
                    <flux:heading size="lg">Nenhum template encontrado</flux:heading>
                    <flux:text class="mt-2">Ajuste a busca, o tipo documental, a remuneração ou a situação.</flux:text>
                    <flux:button class="mt-5" variant="ghost" wire:click="clearFilters">Limpar filtros</flux:button>
                @else
                    <flux:heading size="lg">Nenhum template cadastrado</flux:heading>
                    <flux:text class="mt-2">Os templates do seu campus aparecerão aqui.</flux:text>
                    @can('create', DocumentTemplate::class)
                        <flux:button class="mt-5" :href="route('document-templates.create')" wire:navigate>Cadastrar template</flux:button>
                    @endcan
                @endif
            </div>
        @endif
    </x-loading-overlay>
</div>
