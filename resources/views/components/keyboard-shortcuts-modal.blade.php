@props(['showCommandPalette' => false])

@php
    $paletteItems = $showCommandPalette && auth()->check()
        ? app(\App\Support\SectorCommandPaletteItems::class)->forUser(auth()->user())
        : [];
@endphp

<div
    x-data="{
        isMac: Boolean(window.__sgeIsMac),
        showCommands: false,
        commandFilter: '',
        paletteItems: @js($paletteItems),

        normalize(text) {
            return String(text || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR');
        },

        filteredPaletteGroups() {
            const term = this.normalize(this.commandFilter.trim());
            const tokens = term.split(/\s+/).filter(Boolean);

            const matches = tokens.length === 0
                ? this.paletteItems
                : this.paletteItems.filter((item) => {
                    const haystack = this.normalize(item.title + ' ' + item.keywords.join(' ') + ' ' + item.group);
                    return tokens.every((token) => haystack.includes(token));
                });

            const grouped = [];
            for (const groupName of ['Páginas', 'Ações rápidas']) {
                const items = matches.filter((i) => i.group === groupName);
                if (items.length > 0) {
                    grouped.push({ key: groupName, label: groupName, items });
                }
            }

            return grouped;
        },

        openItem(item) {
            this.$flux.modal('keyboard-shortcuts').close();
            if (item.download) {
                window.location.href = item.href;
                return;
            }
            if (window.Livewire?.navigate) {
                window.Livewire.navigate(item.href);
            } else {
                window.location.href = item.href;
            }
        },
    }"
    x-on:sge:open-shortcuts.window="showCommands = false; commandFilter = ''; $flux.modal('keyboard-shortcuts').show()"
>
    <flux:modal name="keyboard-shortcuts" class="w-full max-w-4xl">
        <div class="space-y-5 focus:outline-none" tabindex="-1" autofocus>
            <div class="flex flex-wrap items-start justify-between gap-3 pe-8">
                <div>
                    <flux:heading size="lg" level="2">Atalhos de teclado</flux:heading>
                    <flux:text class="mt-1">Navegue mais rápido pelo sistema usando o teclado.</flux:text>
                </div>

                <span class="inline-flex items-center gap-1.5 rounded-full border border-zinc-200 bg-zinc-50 px-2.5 py-1 text-xs font-medium text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                    <template x-if="isMac">
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M18 3a3 3 0 0 0-3 3v12a3 3 0 0 0 3 3 3 3 0 0 0 3-3 3 3 0 0 0-3-3H6a3 3 0 0 0-3 3 3 3 0 0 0 3 3 3 3 0 0 0 3-3V6a3 3 0 0 0-3-3 3 3 0 0 0-3 3 3 3 0 0 0 3 3h12a3 3 0 0 0 3-3 3 3 0 0 0-3-3z" />
                        </svg>
                    </template>
                    <template x-if="!isMac">
                        <flux:icon.computer-desktop variant="micro" class="size-3.5" />
                    </template>
                    <span x-text="isMac ? 'macOS' : 'Windows / Linux'">Windows / Linux</span>
                </span>
            </div>

            <dl class="grid grid-cols-1 gap-x-8 text-sm md:grid-cols-2">
                @if ($showCommandPalette)
                    <div class="hidden items-center justify-between gap-4 border-b border-zinc-200 py-2.5 dark:border-zinc-700 lg:flex">
                        <dt class="text-zinc-700 dark:text-zinc-200">Abrir busca rápida e navegação (Setor)</dt>
                        <dd class="flex shrink-0 items-center gap-1">
                            <kbd class="inline-flex items-center gap-1 rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-800 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100">
                                <span x-text="isMac ? '⌘' : 'Ctrl'">Ctrl</span>
                            </kbd>
                            <span class="text-zinc-400">+</span>
                            <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-800 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100">K</kbd>
                        </dd>
                    </div>
                @endif

                <div class="flex items-center justify-between gap-4 border-b border-zinc-200 py-2.5 dark:border-zinc-700">
                    <dt class="text-zinc-700 dark:text-zinc-200">Focar o campo de busca da tela</dt>
                    <dd class="shrink-0">
                        <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-800 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100">/</kbd>
                    </dd>
                </div>

                <div class="flex items-center justify-between gap-4 border-b border-zinc-200 py-2.5 dark:border-zinc-700">
                    <dt class="text-zinc-700 dark:text-zinc-200">Navegar entre linhas da tabela</dt>
                    <dd class="flex shrink-0 items-center gap-1">
                        <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-800 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100">↑</kbd>
                        <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-800 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100">↓</kbd>
                        <span class="text-zinc-400">+</span>
                        <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-800 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100">↵</kbd>
                    </dd>
                </div>

                <div class="flex items-center justify-between gap-4 border-b border-zinc-200 py-2.5 dark:border-zinc-700">
                    <dt class="text-zinc-700 dark:text-zinc-200">Enviar formulário atual (inclusive em texto longo)</dt>
                    <dd class="flex shrink-0 items-center gap-1">
                        <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-800 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100" x-text="isMac ? '⌘' : 'Ctrl'">Ctrl</kbd>
                        <span class="text-zinc-400">+</span>
                        <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-800 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100">↵</kbd>
                    </dd>
                </div>

                <div class="flex items-center justify-between gap-4 border-b border-zinc-200 py-2.5 dark:border-zinc-700 md:border-b-0">
                    <dt class="text-zinc-700 dark:text-zinc-200">Fechar janela, menu ou sair da busca</dt>
                    <dd class="shrink-0">
                        <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-800 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100" x-text="isMac ? '⎋ Esc' : 'Esc'">Esc</kbd>
                    </dd>
                </div>

                <div class="flex items-center justify-between gap-4 py-2.5">
                    <dt class="text-zinc-700 dark:text-zinc-200">Exibir esta lista de atalhos</dt>
                    <dd class="shrink-0">
                        <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-800 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100">?</kbd>
                    </dd>
                </div>
            </dl>

            @if ($showCommandPalette && count($paletteItems) > 0)
                <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <button
                        type="button"
                        x-on:click="showCommands = !showCommands"
                        x-bind:aria-expanded="showCommands"
                        class="flex w-full items-center justify-between gap-3 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-left text-sm font-medium text-zinc-700 transition-colors hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800/70 dark:text-zinc-200 dark:hover:bg-zinc-800"
                    >
                        <span class="flex items-center gap-2">
                            <flux:icon.magnifying-glass variant="mini" class="shrink-0 text-zinc-500 dark:text-zinc-400" />
                            <span>Ver páginas, ações e palavras-chave da busca rápida</span>
                            <span class="rounded-full bg-zinc-200 px-2 py-0.5 text-[11px] font-medium text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">{{ count($paletteItems) }}</span>
                        </span>
                        <flux:icon.chevron-down
                            variant="mini"
                            class="shrink-0 text-zinc-500 transition-transform duration-150 dark:text-zinc-400"
                            x-bind:class="showCommands ? 'rotate-180' : ''"
                        />
                    </button>

                    <div x-cloak x-show="showCommands" class="mt-3 space-y-3">
                        <p class="text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">
                            Na busca rápida (<kbd class="rounded border border-zinc-300 bg-zinc-100 px-1 py-0.5 font-mono text-[10px] dark:border-zinc-600 dark:bg-zinc-700" x-text="isMac ? '⌘K' : 'Ctrl+K'">Ctrl+K</kbd>), digite qualquer termo abaixo. Use <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1 py-0.5 font-mono text-[10px] dark:border-zinc-600 dark:bg-zinc-700">&gt;</kbd> no início para filtrar só páginas/ações e <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1 py-0.5 font-mono text-[10px] dark:border-zinc-600 dark:bg-zinc-700">Tab</kbd> para pular entre grupos.
                        </p>

                        <div class="relative">
                            <flux:icon.magnifying-glass variant="mini" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-zinc-400" />
                            <input
                                x-model="commandFilter"
                                type="text"
                                placeholder="Filtrar por página, ação ou palavra-chave…"
                                aria-label="Filtrar páginas, ações e palavras-chave"
                                class="w-full rounded-lg border border-zinc-200 bg-white py-1.5 pr-3 pl-9 text-xs text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-400 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:focus:border-zinc-500"
                            />
                        </div>

                        <div class="scroll-fade-y max-h-[52vh] space-y-5 overflow-y-auto overscroll-contain py-1 pe-2">
                            <template x-for="group in filteredPaletteGroups()" :key="group.key">
                                <div class="space-y-2.5">
                                    <div class="text-[11px] font-semibold tracking-wider text-zinc-500 uppercase dark:text-zinc-400" x-text="group.label"></div>
                                    <div class="grid grid-cols-1 gap-2.5 md:grid-cols-2">
                                        <template x-for="item in group.items" :key="item.id">
                                            <div class="flex flex-col justify-between gap-2 rounded-lg border border-zinc-200 bg-zinc-50/70 p-3 dark:border-zinc-700 dark:bg-zinc-900/50">
                                                <div class="flex items-start justify-between gap-2">
                                                    <a
                                                        :href="item.href"
                                                        x-on:click.prevent="openItem(item)"
                                                        class="group inline-flex items-center gap-1.5 text-sm font-medium text-zinc-900 hover:underline dark:text-zinc-100"
                                                    >
                                                        <span x-text="item.title"></span>
                                                        <span class="text-xs font-normal text-zinc-400 opacity-0 transition-opacity group-hover:opacity-100" x-text="item.download ? 'Baixar' : 'Abrir ↗'"></span>
                                                    </a>
                                                </div>
                                                <div class="flex flex-wrap gap-1">
                                                    <template x-for="kw in item.keywords" :key="kw">
                                                        <span class="rounded bg-white px-1.5 py-0.5 text-[11px] text-zinc-600 ring-1 ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-700" x-text="kw"></span>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <div x-cloak x-show="filteredPaletteGroups().length === 0" class="py-6 text-center text-xs text-zinc-500 dark:text-zinc-400">
                                Nenhuma página ou palavra-chave encontrada para <span class="font-medium text-zinc-700 dark:text-zinc-200" x-text="commandFilter"></span>.
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
