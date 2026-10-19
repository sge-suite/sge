<?php

use App\Models\DocumentTemplate;
use App\Support\TemplateVariableCatalog;
use App\Support\TemplateTextFormatter;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Guia de variáveis DOCX')] class extends Component
{
    #[Url(except: '')]
    public string $search = '';

    public function boot(): void
    {
        Gate::authorize('viewAny', DocumentTemplate::class);
    }

    /** @return array<string, array<string, mixed>> */
    #[Computed]
    public function variables(): array
    {
        $search = mb_strtolower(mb_substr(trim($this->search), 0, 255));

        return array_filter(app(TemplateVariableCatalog::class)->all(), function (array $variable) use ($search): bool {
            return $search === '' || str_contains(mb_strtolower(implode(' ', [$variable['name'], $variable['description'], $variable['origin']])), $search);
        });
    }

}; ?>

<div class="w-full space-y-8">
    <flux:breadcrumbs><flux:breadcrumbs.item :href="route('document-templates.index')" wire:navigate>Templates DOCX</flux:breadcrumbs.item><flux:breadcrumbs.item>Guia de variáveis</flux:breadcrumbs.item></flux:breadcrumbs>
    <div><flux:heading size="xl" level="1">Guia de variáveis</flux:heading><flux:text class="mt-2">Use os nomes exatos no formato ${NOME_DA_VARIAVEL}. Todos os exemplos são fictícios.</flux:text></div>
    <flux:callout><flux:callout.heading>Como preparar o original</flux:callout.heading><flux:callout.text>Todas as variáveis disponíveis podem ser usadas em qualquer tipo de template, independentemente da classificação de remuneração. Não há lista de marcadores obrigatórios; use somente os necessários ao conteúdo do modelo. Variáveis reservadas ainda não podem ser usadas. Fontes incorporadas e links para sites e e-mails são permitidos. Não inclua macros, arquivos anexados ou campos automáticos. O sistema verifica a estrutura e as variáveis, sem analisar dados pessoais. TCE, credenciamento, SEDUC, EMATER e rescisão usam o tipo Documento principal.</flux:callout.text></flux:callout>
    <flux:callout heading="Formatação e variações de texto"><flux:callout.text>Aplique fonte, tamanho, negrito e itálico diretamente ao marcador completo no Word ou LibreOffice. Para transformar o texto, use um único modificador: ${DISCENTE_NOME|MAIUSCULAS}, ${DISCENTE_NOME|MINUSCULAS}, ${DISCENTE_NOME|PRIMEIRA_MAIUSCULA} ou ${DISCENTE_NOME|INICIAIS_MAIUSCULAS}. Este último mantém partículas internas como “de”, “da”, “em” e “e” minúsculas. Sem modificador, a grafia cadastrada é preservada. A geração futura deverá manter os estilos do original.</flux:callout.text></flux:callout>
    <flux:callout heading="Modelos com e sem remuneração"><flux:callout.text>O texto jurídico fica em DOCXs separados. A classificação do modelo não restringe as variáveis disponíveis. Use somente os valores de bolsa e auxílio; não existe variável de parágrafo completo. Use os valores monetários somente quando fizerem sentido no conteúdo do modelo.</flux:callout.text></flux:callout>
    <flux:input type="search" label="Buscar nome, significado ou origem" icon="magnifying-glass" wire:model.live.debounce.300ms="search" maxlength="255" />
    <x-loading-overlay target="search">
        <div class="space-y-4">
            @forelse ($this->variables as $name => $variable)
                <x-accordion :title="'${'.$name.'} · '.$variable['description']" wire:key="variable-{{ $name }}">
                    <div class="flex flex-wrap items-center gap-3"><code class="break-all text-sm font-semibold">{{ '${'.$name.'}' }}</code><flux:badge :color="$variable['available'] ? 'green' : 'amber'">{{ ! $variable['available'] ? 'Reservada · indisponível' : 'Disponível' }}</flux:badge></div>
                    <flux:text>{{ $variable['description'] }}</flux:text>
                    @if ($name === 'CAMPUS_IDENTIFICACAO')
                        <flux:text>Exemplo formatado: @foreach (app(TemplateTextFormatter::class)->campusIdentification('Cidade Exemplo') as $segment)<span @class(['italic' => $segment['italic']])>{{ $segment['text'] }}</span>@endforeach. A fonte e o tamanho vêm do marcador; somente “Campus” fica em itálico.</flux:text>
                    @endif
                    <dl class="grid gap-4 text-sm md:grid-cols-2">
                        @foreach (['Tipo' => $variable['type'], 'Origem' => $variable['origin'], 'Formato' => $variable['format'], 'Sensibilidade' => $variable['sensitivity'], 'Condição' => $variable['condition'], 'Snapshot' => $variable['snapshot'], 'Valor vazio' => $variable['empty'], 'Exemplo fictício' => $variable['example']] as $label => $value)
                            <div wire:key="variable-{{ $name }}-{{ $loop->index }}"><dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="mt-1 wrap-break-word">{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                    @if ($variable['available'])
                        <flux:heading size="sm">Variações do texto</flux:heading>
                        <dl class="grid gap-4 text-sm md:grid-cols-2">
                            @foreach (TemplateTextFormatter::MODIFIERS as $modifier)
                                <div wire:key="variable-{{ $name }}-{{ $modifier }}"><dt><code class="break-all">{{ '${'.$name.'|'.$modifier.'}' }}</code></dt><dd class="mt-1 wrap-break-word">@if ($name === 'CAMPUS_IDENTIFICACAO')@foreach (app(TemplateTextFormatter::class)->campusIdentification('Cidade Exemplo', $modifier) as $segment)<span @class(['italic' => $segment['italic']])>{{ $segment['text'] }}</span>@endforeach@else{{ app(TemplateTextFormatter::class)->format($variable['example'], $modifier) }}@endif</dd></div>
                            @endforeach
                        </dl>
                    @endif
                </x-accordion>
            @empty
                <flux:text>Nenhuma variável encontrada. Ajuste a busca.</flux:text>
            @endforelse
        </div>
    </x-loading-overlay>
</div>
