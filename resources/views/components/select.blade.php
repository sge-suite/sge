@props([
    'label', 'options' => [], 'name' => '', 'value' => '', 'selectedLabel' => '',
    'placeholder' => 'Selecione uma opção', 'searchable' => false,
    'disabled' => false, 'required' => false, 'invalid' => false, 'error' => '', 'openOnMount' => false,
    'empty' => 'Nenhuma opção encontrada.',
    'icon' => null, 'optionIcons' => [],
])

@php($selectId = $attributes->get('id') ?? 'select-'.md5($name.$label.$attributes->wire('model')->value()))

<flux:field class="w-full min-w-0">
    <flux:label :for="$selectId" :required="$required" :class="$disabled ? '' : 'opacity-100!'">{{ $label }}</flux:label>
    <div
        {{ $attributes->whereStartsWith(['wire:model', 'x-model']) }}
        {{ $attributes->only(['wire:key', 'class'])->class('relative w-full min-w-0') }}
        x-data="{
        value: String(@js((string) $value)),
        openOnMount: @js($openOnMount),
        query: '',
        open: false,
        upwards: false,
        activeValue: '',
        missing: false,
        labelOverflows: false,
        labelScrollDistance: '0px',
        labelResizeObserver: null,
        optionsRevision: 0,
        optionsObserver: null,

        init() {
            this.$watch('value', () => {
                this.missing = false;
                if (!this.open) this.close(false);
                this.measureLabel();
            });
            this.$nextTick(() => {
                this.optionsObserver = new MutationObserver(() => {
                    this.optionsRevision++;
                    const options = this.options();
                    if (!options.some(option => option.dataset.value === this.activeValue)) {
                        this.activeValue = options.find(option => option.dataset.value === String(this.value))?.dataset.value
                            ?? options[0]?.dataset.value ?? '';
                    }
                    this.measureLabel();
                });
                this.optionsObserver.observe(this.$refs.list, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ['data-value', 'data-label', 'disabled'],
                });

                this.labelResizeObserver = new ResizeObserver(() => this.measureLabel());
                this.labelResizeObserver.observe(this.$refs.labelViewport);
                this.labelResizeObserver.observe(this.$refs.labelText);
                this.measureLabel();
                if (this.openOnMount) this.show(true);
            });
        },

        destroy() {
            this.optionsObserver?.disconnect();
            this.labelResizeObserver?.disconnect();
        },

        measureLabel() {
            this.$nextTick(() => {
                const viewport = this.$refs.labelViewport;
                const text = this.$refs.labelText;

                if (!viewport || !text) return;

                const range = document.createRange();
                range.selectNodeContents(text);

                const textBounds = range.getBoundingClientRect();
                const distance = Math.ceil(Math.max(0, textBounds.width - viewport.clientWidth));

                this.labelOverflows = distance > 1;
                this.labelScrollDistance = `-${distance}px`;
            });
        },

        normalize(text) {
            return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR');
        },

        matches(label) {
            return this.normalize(label).includes(this.normalize(this.query.trim()));
        },

        options() {
            this.optionsRevision;
            return Array.from(this.$refs.list.querySelectorAll('[data-select-option]'))
                .filter(option => !option.disabled && this.matches(option.dataset.label));
        },

        label() {
            this.optionsRevision;
            const option = Array.from(this.$refs.list.querySelectorAll('[data-select-option]'))
                .find(option => option.dataset.value === String(this.value));

            if (option) return option.dataset.label;
            if (this.value && String(this.value) === this.$root.dataset.selectedValue) {
                return this.$root.dataset.selectedLabel;
            }

            return this.$root.dataset.placeholder;
        },

        show(focusTrigger = false) {
            if (this.$refs.trigger.disabled) return;
            this.upwards = this.$refs.trigger.getBoundingClientRect().bottom + 320 > window.innerHeight
                && this.$refs.trigger.getBoundingClientRect().top > 320;
            this.open = true;
            this.$nextTick(() => {
                const options = this.options();
                this.activeValue = options.find(option => option.dataset.value === String(this.value))?.dataset.value
                    ?? '';
                const focusTarget = focusTrigger
                    ? this.$refs.trigger
                    : (this.$refs.search?.querySelector('input') ?? this.$refs.list);
                requestAnimationFrame(() => focusTarget.focus());
            });
        },

        close(refocus = true) {
            this.open = false;
            if (refocus) this.$refs.trigger.focus();
        },

        move(direction) {
            if (!this.open) return this.show();
            const options = this.options();
            if (!options.length) return;
            const index = options.findIndex(option => option.dataset.value === this.activeValue);
            const nextIndex = index === -1
                ? (direction > 0 ? 0 : options.length - 1)
                : Math.max(0, Math.min(index + direction, options.length - 1));
            const next = options[nextIndex];
            this.activeValue = next.dataset.value;
            next.scrollIntoView({ block: 'nearest' });
        },

        choose(option) {
            if (!option || option.disabled) return;
            this.value = option.dataset.value;
            this.query = '';
            this.missing = false;
            this.close();
        },

        chooseActive() {
            const options = this.options();
            this.choose(options.find(option => option.dataset.value === this.activeValue) ?? options[0]);
        },

        activeId() {
            return this.options().find(option => option.dataset.value === this.activeValue)?.id ?? '';
        },
    }" x-modelable="value"
        data-selected-value="{{ $value }}" data-selected-label="{{ $selectedLabel }}" data-placeholder="{{ $placeholder }}"
        x-on:click.outside="close(false)"
        x-on:keydown.escape.stop.prevent="close()"
        x-on:keydown="
            if ($event.key === 'ArrowDown') { $event.preventDefault(); move(1); }
            else if ($event.key === 'ArrowUp') { $event.preventDefault(); move(-1); }
        "
        x-on:keydown.enter="if (open) { $event.preventDefault(); $event.stopPropagation(); chooseActive(); }"
        x-on:keydown.tab="close(false)"
    >
        <select name="{{ $name }}" x-model="value" tabindex="-1" aria-hidden="true" class="sr-only" @required($required) @disabled($disabled)
            x-on:invalid.prevent="missing = true; show()">
            <option value=""></option>
            @if ($value !== '' && ! array_key_exists($value, $options))
                <option value="{{ $value }}" selected>{{ $selectedLabel }}</option>
            @endif
            @foreach ($options as $optionValue => $optionLabel)
                <option wire:key="{{ $selectId }}-native-{{ $optionValue }}" value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
            @endforeach
        </select>
        <button type="button" id="{{ $selectId }}" x-ref="trigger" data-flux-control
            {{ $attributes->whereStartsWith('wire:')->except(['wire:key'])->whereDoesntStartWith('wire:model') }}
            @disabled($disabled) aria-haspopup="listbox" aria-expanded="false" x-bind:aria-expanded="open"
            x-bind:aria-label="label()" x-bind:title="label()"
            aria-controls="{{ $selectId }}-list" @if($invalid) aria-invalid="true" @endif
            x-bind:aria-invalid="missing || @js((bool) $invalid)" @if($error !== '') aria-describedby="{{ $selectId }}-error" @endif
            class="select-trigger flex h-10 w-full min-w-0 items-center justify-between gap-3 rounded-lg border bg-white px-3 text-start text-sm shadow-xs outline-hidden transition disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white/10 dark:text-white {{ $invalid ? 'border-red-500 dark:border-red-400' : 'border-zinc-200 hover:border-zinc-300 dark:border-white/10 dark:hover:border-white/20' }}"
            x-bind:class="{ 'text-zinc-400 dark:text-zinc-400': !value, 'border-red-500 ring-2 ring-red-500': missing }"
            x-on:click="open ? close() : show()"
        >
            <span class="flex min-w-0 flex-1 items-center gap-2">
                @foreach ($optionIcons as $optionValue => $optionIcon)
                    <span x-cloak x-show="String(value) === @js((string) $optionValue)" class="shrink-0">
                        <flux:icon :name="$optionIcon" variant="mini" class="text-zinc-500 dark:text-zinc-300" />
                    </span>
                @endforeach
                @if ($icon)
                    <span x-cloak x-show="!Object.hasOwn(@js($optionIcons), String(value))" class="shrink-0">
                        <flux:icon :name="$icon" variant="mini" class="text-zinc-400 dark:text-zinc-400" />
                    </span>
                @endif
                <span
                    x-ref="labelViewport"
                    x-bind:class="labelOverflows && !open ? 'select-label-fade' : ''"
                    class="select-label-viewport block min-w-0 flex-1 overflow-hidden"
                >
                    <span
                        x-ref="labelText"
                        x-bind:class="labelOverflows && !open ? 'select-label-marquee' : ''"
                        x-bind:style="'--select-label-distance: ' + labelScrollDistance"
                        class="block w-max whitespace-nowrap"
                        x-text="label()"
                    >{{ $selectedLabel ?: ($options[$value] ?? $placeholder) }}</span>
                </span>
            </span>
            <flux:icon.chevron-down variant="micro" class="shrink-0 text-zinc-400" />
        </button>
        <div x-cloak x-show="open" x-transition.opacity.duration.100ms
            x-bind:class="upwards ? 'bottom-full mb-1' : 'top-full mt-1'"
            class="select-panel absolute inset-x-0 z-30 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-600 dark:bg-zinc-700 motion-reduce:transition-none">
            @if ($searchable || isset($search))
                <div x-ref="search" class="select-search border-b border-zinc-200/80 px-1 py-1 dark:border-zinc-600" x-on:input="activeValue = ''">
                    @isset($search)
                        {{ $search }}
                    @else
                        <flux:input x-model="query" size="sm" icon="magnifying-glass" placeholder="Buscar opção…" aria-label="Buscar {{ mb_strtolower($label) }}"
                            role="combobox" aria-autocomplete="list" x-bind:aria-expanded="open" aria-controls="{{ $selectId }}-list" x-bind:aria-activedescendant="activeId()" />
                    @endisset
                </div>
            @endif
            <div id="{{ $selectId }}-list" x-ref="list" role="listbox" aria-label="{{ $label }}" tabindex="-1"
                x-bind:aria-activedescendant="activeId()" class="select-options max-h-60 overflow-y-auto p-1 outline-hidden">
                @foreach ($options as $optionValue => $optionLabel)
                    <button type="button" role="option" tabindex="-1" id="{{ $selectId }}-option-{{ $loop->index }}"
                        wire:key="{{ $name ?: $label }}-option-{{ $optionValue }}" data-select-option data-value="{{ $optionValue }}" data-label="{{ $optionLabel }}"
                        aria-selected="{{ (string) $value === (string) $optionValue ? 'true' : 'false' }}"
                        x-bind:aria-selected="String(value) === @js((string) $optionValue)" x-show="matches(@js($optionLabel))"
                        x-bind:class="{ 'bg-zinc-200 dark:bg-white/15': activeValue === @js((string) $optionValue) }"
                        class="flex w-full min-w-0 items-center justify-between gap-3 rounded-md px-2 py-1.5 text-start text-sm text-zinc-800 hover:bg-zinc-200 dark:text-zinc-100 dark:hover:bg-white/15"
                        x-on:mouseenter="activeValue = @js((string) $optionValue)" x-on:click="choose($el)">
                        <span class="flex min-w-0 flex-1 items-start gap-2">
                            @if (isset($optionIcons[$optionValue]))
                                <flux:icon :name="$optionIcons[$optionValue]" variant="mini" class="shrink-0 text-zinc-500 dark:text-zinc-300" />
                            @endif
                            <span class="min-w-0 whitespace-normal break-words">{{ $optionLabel }}</span>
                        </span>
                        <span class="flex size-4 shrink-0 items-center justify-center">
                            <span x-show="String(value) === @js((string) $optionValue)">
                                <flux:icon.check variant="micro" />
                            </span>
                        </span>
                    </button>
                @endforeach
                <p x-cloak x-show="options().length === 0" class="px-2 py-3 text-sm text-zinc-500 dark:text-zinc-400" role="status">{{ $empty }}</p>
            </div>
        </div>
        <p x-cloak x-show="missing" class="mt-2 text-sm text-red-500 dark:text-red-400" role="alert">Selecione uma opção.</p>
    </div>
    @if ($error !== '')
        <flux:error id="{{ $selectId }}-error" :message="$error" />
    @endif
</flux:field>
