@props(['showCommandPalette' => false])

<div
    x-data="{ isMac: Boolean(window.__sgeIsMac) }"
    x-on:sge:open-shortcuts.window="$flux.modal('keyboard-shortcuts').show()"
>
    <flux:modal name="keyboard-shortcuts" class="md:w-lg">
        <div class="space-y-5">
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

            <dl class="divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                @if ($showCommandPalette)
                    <div class="hidden items-center justify-between gap-4 py-2.5 lg:flex">
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

                <div class="flex items-center justify-between gap-4 py-2.5">
                    <dt class="text-zinc-700 dark:text-zinc-200">Focar o campo de busca da tela</dt>
                    <dd class="shrink-0">
                        <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-800 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100">/</kbd>
                    </dd>
                </div>

                <div class="flex items-center justify-between gap-4 py-2.5">
                    <dt class="text-zinc-700 dark:text-zinc-200">Navegar entre linhas da tabela</dt>
                    <dd class="flex shrink-0 items-center gap-1">
                        <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-800 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100">↑</kbd>
                        <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-800 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100">↓</kbd>
                        <span class="text-zinc-400">+</span>
                        <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-800 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100">↵</kbd>
                    </dd>
                </div>

                <div class="flex items-center justify-between gap-4 py-2.5">
                    <dt class="text-zinc-700 dark:text-zinc-200">Enviar formulário atual (inclusive em texto longo)</dt>
                    <dd class="flex shrink-0 items-center gap-1">
                        <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-800 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100" x-text="isMac ? '⌘' : 'Ctrl'">Ctrl</kbd>
                        <span class="text-zinc-400">+</span>
                        <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-800 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100">↵</kbd>
                    </dd>
                </div>

                <div class="flex items-center justify-between gap-4 py-2.5">
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

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button type="button" variant="outline">Fechar</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
