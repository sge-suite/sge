<?php

use App\Enums\AffiliationType;
use App\Support\ActiveAffiliationContext;
use App\Support\SectorCommandPaletteItems;
use App\Support\SectorCommandPaletteSearch;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $affiliationId;

    public function mount(ActiveAffiliationContext $context): void
    {
        $affiliation = $context->currentFor(auth()->user(), app('session.store'));

        abort_unless($affiliation?->type === AffiliationType::InternshipOffice, 403);

        $this->affiliationId = $affiliation->id;
    }

    public function hydrate(ActiveAffiliationContext $context): void
    {
        $affiliation = $context->currentFor(auth()->user(), app('session.store'));

        abort_unless(
            $affiliation?->type === AffiliationType::InternshipOffice && $affiliation->id === $this->affiliationId,
            403,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function searchRecords(string $term, SectorCommandPaletteSearch $search): array
    {
        return $search->search(auth()->user(), $term);
    }

    /**
     * @return list<array{id: string, group: string, title: string, keywords: string, href: string, icon: string, download: bool}>
     */
    #[Computed]
    public function localItems(): array
    {
        return array_map(
            static fn (array $item): array => [
                ...$item,
                'keywords' => implode(' ', $item['keywords']),
            ],
            app(SectorCommandPaletteItems::class)->forUser(auth()->user()),
        );
    }
}; ?>

<div
    x-data="{
        isMac: Boolean(window.__sgeIsMac),
        query: '',
        activeIndex: 0,
        localItems: @js($this->localItems),
        remoteGroups: [],
        remoteLoading: false,
        remoteUnavailable: false,
        searchTimer: null,
        searchSeq: 0,

        init() {
            this.$watch('query', (val) => {
                this.activeIndex = 0;
                this.queueRemoteSearch(val);
            });
        },

        destroy() {
            clearTimeout(this.searchTimer);
        },

        normalize(text) {
            return String(text || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR');
        },

        isCommandOnly() {
            return this.query.trim().startsWith('>');
        },

        localTerm() {
            const raw = this.query.trim();
            return this.normalize(raw.startsWith('>') ? raw.slice(1).trim() : raw);
        },

        editDistance(a, b, maxDist) {
            const lenA = a.length;
            const lenB = b.length;
            if (Math.abs(lenA - lenB) > maxDist) return maxDist + 1;
            let prev = Array.from({ length: lenB + 1 }, (_, i) => i);
            for (let i = 1; i <= lenA; i++) {
                const curr = [i];
                let rowMin = curr[0];
                for (let j = 1; j <= lenB; j++) {
                    const cost = a[i - 1] === b[j - 1] ? 0 : 1;
                    curr[j] = Math.min(prev[j] + 1, curr[j - 1] + 1, prev[j - 1] + cost);
                    if (curr[j] < rowMin) rowMin = curr[j];
                }
                if (rowMin > maxDist) return maxDist + 1;
                prev = curr;
            }
            return prev[lenB];
        },

        matchesTerm(haystack, term) {
            const normalizedHaystack = this.normalize(haystack);
            const words = normalizedHaystack.split(/\s+/).filter(Boolean);
            const tokens = term.split(/\s+/).filter(Boolean);

            return tokens.every((token) => {
                if (normalizedHaystack.includes(token)) return true;
                if (token.length < 4) return false;
                const maxDist = token.length >= 7 ? 2 : 1;
                return words.some((word) => {
                    if (this.editDistance(token, word, maxDist) <= maxDist) return true;
                    const prefix = word.slice(0, Math.min(word.length, token.length + 1));
                    return prefix.length >= 4 && this.editDistance(token, prefix, maxDist) <= maxDist;
                });
            });
        },

        filteredLocalGroups() {
            const term = this.localTerm();
            const matches = term === ''
                ? this.localItems.slice(0, 8)
                : this.localItems.filter((item) => this.matchesTerm(item.title + ' ' + item.keywords + ' ' + item.group, term));

            const grouped = [];
            for (const groupName of ['Páginas', 'Ações rápidas']) {
                const items = matches.filter((i) => i.group === groupName);
                if (items.length > 0) {
                    grouped.push({ key: groupName, label: groupName, items });
                }
            }
            return grouped;
        },

        allGroups() {
            const groups = [];
            for (const group of this.remoteGroups) {
                if (Array.isArray(group.items) && group.items.length > 0) {
                    groups.push(group);
                }
            }
            for (const group of this.filteredLocalGroups()) {
                if (Array.isArray(group.items) && group.items.length > 0) {
                    groups.push(group);
                }
            }
            return groups;
        },

        flatItems() {
            return this.allGroups().flatMap((group) => group.items);
        },

        activeItemId() {
            const active = this.flatItems()[this.activeIndex];
            return active ? 'cmd-item-' + active.id : null;
        },

        indexOfItem(id) {
            return this.flatItems().findIndex((item) => item.id === id);
        },

        queueRemoteSearch(raw) {
            clearTimeout(this.searchTimer);
            const seq = ++this.searchSeq;
            const trimmed = raw.trim();

            if (trimmed.startsWith('>') || trimmed.length < 2) {
                this.remoteGroups = [];
                this.remoteLoading = false;
                this.remoteUnavailable = false;
                return;
            }

            this.remoteLoading = true;
            this.remoteUnavailable = false;
            this.searchTimer = setTimeout(() => this.runRemoteSearch(trimmed, seq), 280);
        },

        async runRemoteSearch(term, seq) {
            try {
                const result = await this.$wire.searchRecords(term);
                if (seq !== this.searchSeq) {
                    return;
                }
                this.remoteUnavailable = Boolean(result?.unavailable);
                this.remoteGroups = Array.isArray(result?.groups) ? result.groups : [];
                this.activeIndex = 0;
            } catch {
                if (seq === this.searchSeq) {
                    this.remoteUnavailable = true;
                    this.remoteGroups = [];
                }
            } finally {
                if (seq === this.searchSeq) {
                    this.remoteLoading = false;
                }
            }
        },

        openPalette() {
            if (!window.matchMedia('(min-width: 1024px)').matches) {
                return;
            }
            this.query = '';
            this.activeIndex = 0;
            this.remoteGroups = [];
            this.remoteUnavailable = false;
            this.$flux.modal('sector-command-palette').show();
            this.$nextTick(() => {
                this.$refs.searchInput?.focus();
                this.$refs.searchInput?.select();
            });
        },

        scrollActiveIntoView(includeGroupHeader = false) {
            this.$nextTick(() => {
                const active = this.flatItems()[this.activeIndex];
                if (!active) return;
                const el = document.getElementById('cmd-item-' + active.id);
                if (!el) return;
                if (includeGroupHeader) {
                    const groupEl = el.closest('[data-cmd-group]');
                    const heading = groupEl?.querySelector('[data-cmd-group-heading]');
                    heading?.scrollIntoView({ block: 'nearest' });
                }
                el.scrollIntoView({ block: 'nearest' });
            });
        },

        move(delta) {
            const items = this.flatItems();
            if (!items.length) {
                return;
            }
            this.activeIndex = (this.activeIndex + delta + items.length) % items.length;
            this.scrollActiveIntoView(false);
        },

        moveGroup(delta) {
            const groups = this.allGroups();
            if (!groups.length) {
                return;
            }
            const groupStarts = [];
            let offset = 0;
            for (const group of groups) {
                groupStarts.push(offset);
                offset += group.items.length;
            }
            let currentGroupIndex = 0;
            for (let i = 0; i < groupStarts.length; i++) {
                if (this.activeIndex >= groupStarts[i]) {
                    currentGroupIndex = i;
                }
            }
            const nextGroupIndex = (currentGroupIndex + delta + groups.length) % groups.length;
            this.activeIndex = groupStarts[nextGroupIndex];
            this.$refs.searchInput?.focus({ preventScroll: true });
            this.scrollActiveIntoView(true);
        },

        selectActive() {
            const items = this.flatItems();
            const target = items[this.activeIndex] ?? items[0];
            if (target) {
                this.navigateTo(target);
            }
        },

        navigateTo(item) {
            this.$flux.modal('sector-command-palette').close();
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
    x-on:keydown.window="
        if (($event.ctrlKey || $event.metaKey) && $event.key.toLowerCase() === 'k' && window.matchMedia('(min-width: 1024px)').matches) {
            $event.preventDefault();
            openPalette();
        }
    "
    x-on:sge:open-command-palette.window="openPalette()"
>
    <flux:modal name="sector-command-palette" :closable="false" class="w-full max-w-4xl overflow-hidden p-0!">
        <div
            class="flex flex-col"
            x-on:keydown.arrow-down.prevent="move(1)"
            x-on:keydown.arrow-up.prevent="move(-1)"
            x-on:keydown.tab.prevent="moveGroup($event.shiftKey ? -1 : 1)"
            x-on:keydown.enter.prevent="selectActive()"
        >
            <div class="flex items-center gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <flux:icon.magnifying-glass variant="mini" class="shrink-0 text-zinc-400" />
                <input
                    x-ref="searchInput"
                    x-model="query"
                    type="text"
                    autofocus
                    role="combobox"
                    aria-expanded="true"
                    aria-controls="sector-command-palette-listbox"
                    :aria-activedescendant="activeItemId()"
                    placeholder="Buscar registros, páginas ou ações (> só comandos)…"
                    aria-label="Busca rápida do setor de estágios"
                    class="w-full bg-transparent text-sm text-zinc-900 placeholder:text-zinc-400 focus:outline-none dark:text-white"
                />
                <span x-cloak x-show="remoteLoading" class="shrink-0 text-xs text-zinc-400">Buscando…</span>
                <button
                    type="button"
                    tabindex="-1"
                    x-on:click="$flux.modal('sector-command-palette').close()"
                    title="Fechar (Esc)"
                    class="shrink-0 rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 transition-colors hover:bg-zinc-200 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-600"
                >
                    <span x-text="isMac ? '⎋ Esc' : 'Esc'">Esc</span>
                </button>
            </div>

            <div x-cloak x-show="remoteUnavailable" class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200">
                A busca de registros está temporariamente indisponível. A navegação por páginas e ações continua funcionando normalmente.
            </div>

            <div id="sector-command-palette-listbox" class="scroll-fade-y max-h-[65vh] overflow-y-auto overscroll-contain p-2" role="listbox" aria-label="Resultados da busca rápida">
                <template x-for="group in remoteGroups" :key="group.key">
                    <div data-cmd-group class="mb-2 last:mb-0">
                        <div data-cmd-group-heading class="px-2.5 py-1.5 text-[11px] font-semibold tracking-wider text-zinc-500 uppercase dark:text-zinc-400" x-text="group.label"></div>
                        <template x-for="item in group.items" :key="item.id">
                            <button
                                type="button"
                                tabindex="-1"
                                :id="'cmd-item-' + item.id"
                                role="option"
                                :aria-selected="indexOfItem(item.id) === activeIndex"
                                x-on:click="navigateTo(item)"
                                x-on:mouseenter="activeIndex = indexOfItem(item.id)"
                                x-bind:class="indexOfItem(item.id) === activeIndex ? 'bg-zinc-100 dark:bg-zinc-700/70' : ''"
                                class="flex w-full items-center justify-between gap-3 rounded-lg px-2.5 py-2 text-left transition-colors"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium text-zinc-900 dark:text-zinc-100" x-text="item.title"></div>
                                    <div x-show="item.subtitle" class="truncate text-xs text-zinc-500 dark:text-zinc-400" x-text="item.subtitle"></div>
                                </div>
                                <span x-show="item.badge" class="shrink-0 rounded-full bg-zinc-200/80 px-2 py-0.5 text-[11px] font-medium text-zinc-700 dark:bg-zinc-600 dark:text-zinc-200" x-text="item.badge"></span>
                            </button>
                        </template>
                    </div>
                </template>

                <template x-for="group in filteredLocalGroups()" :key="group.key">
                    <div data-cmd-group class="mb-2 last:mb-0">
                        <div data-cmd-group-heading class="px-2.5 py-1.5 text-[11px] font-semibold tracking-wider text-zinc-500 uppercase dark:text-zinc-400" x-text="group.label"></div>
                        <template x-for="item in group.items" :key="item.id">
                            <button
                                type="button"
                                tabindex="-1"
                                :id="'cmd-item-' + item.id"
                                role="option"
                                :aria-selected="indexOfItem(item.id) === activeIndex"
                                x-on:click="navigateTo(item)"
                                x-on:mouseenter="activeIndex = indexOfItem(item.id)"
                                x-bind:class="indexOfItem(item.id) === activeIndex ? 'bg-zinc-100 dark:bg-zinc-700/70' : ''"
                                class="flex w-full items-center justify-between gap-3 rounded-lg px-2.5 py-2 text-left transition-colors"
                            >
                                <span class="truncate text-sm font-medium text-zinc-900 dark:text-zinc-100" x-text="item.title"></span>
                                <span class="shrink-0 text-xs text-zinc-400">Ir</span>
                            </button>
                        </template>
                    </div>
                </template>

                <div x-cloak x-show="flatItems().length === 0 && !remoteLoading" class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                    Nenhum resultado encontrado para <span class="font-medium text-zinc-700 dark:text-zinc-200" x-text="query"></span>.
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200 bg-zinc-50 px-4 py-2 text-[11px] text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900/50 dark:text-zinc-400">
                <div class="flex items-center gap-3">
                    <span><kbd class="font-mono">↑↓</kbd> navegar</span>
                    <span><kbd class="font-mono">Tab</kbd> grupos</span>
                    <span><kbd class="font-mono">↵</kbd> abrir</span>
                    <span><kbd class="font-mono">&gt;</kbd> apenas páginas/ações</span>
                </div>
                <span><kbd class="font-mono">?</kbd> todos os atalhos</span>
            </div>
        </div>
    </flux:modal>
</div>
