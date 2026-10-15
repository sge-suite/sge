@props([
    'label' => 'Arquivo', 'description' => '', 'accept' => '', 'files' => null,
    'multiple' => false, 'inline' => false, 'preview' => false,
    'disabled' => false, 'required' => false,
])

@php
    $model = $attributes->wire('model')->value();
    $uploadId = $attributes->get('id') ?? 'upload-'.md5($model.$label);
    $selectedFiles = is_array($files) ? $files : ($files === null ? [] : [$files]);
    $invalid = $model && ($errors->has($model) || $errors->has($model.'.*'));
@endphp

<flux:field {{ $attributes->only('class')->class('w-full min-w-0') }}>
    <flux:label :for="$uploadId" :required="$required">{{ $label }}</flux:label>
    <div x-data="fileUpload" {{ $attributes->only('wire:key') }}
        x-on:livewire-upload-start="start()"
        x-on:livewire-upload-finish="finish()"
        x-on:livewire-upload-cancel="finish()"
        x-on:livewire-upload-error="fail()"
        x-on:livewire-upload-progress="progress = $event.detail.progress"
        x-bind:aria-busy="uploading"
        class="flex min-w-0 flex-col gap-3">
        <div
            @class([
                'relative flex gap-3 rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 transition-colors focus-within:ring-2 focus-within:ring-zinc-500 focus-within:ring-offset-2 dark:border-zinc-600 dark:bg-white/5 dark:focus-within:ring-zinc-300 dark:focus-within:ring-offset-zinc-800',
                'flex-col items-center py-8 text-center' => ! $inline,
                'items-center text-start' => $inline,
                'opacity-50' => $disabled,
                'border-red-500! dark:border-red-400!' => $invalid,
                'hover:border-zinc-400 hover:bg-zinc-100 dark:hover:border-zinc-500 dark:hover:bg-white/10' => ! $disabled,
            ])
            x-bind:class="{ 'border-zinc-500! bg-zinc-100! dark:border-zinc-300! dark:bg-white/10!': dragging }"
            x-on:dragenter="drag($event, true)"
            x-on:dragover="drag($event)"
            x-on:dragleave="leave()"
            x-on:drop="drop($event)">
            <input type="file" id="{{ $uploadId }}" x-ref="input" accept="{{ $accept }}"
                {{ $attributes->whereStartsWith('wire:model') }}
                {{ $attributes->only('name') }}
                aria-label="{{ $attributes->get('aria-label', $label) }}" data-flux-control
                @disabled($disabled) @required($required && $selectedFiles === []) @if($multiple) multiple @endif
                x-bind:disabled="uploading || @js((bool) $disabled)"
                aria-describedby="{{ $uploadId }}-description {{ $uploadId }}-error"
                aria-invalid="{{ $invalid ? 'true' : 'false' }}"
                x-bind:aria-invalid="Boolean(uploadError) || @js((bool) $invalid)"
                wire:loading.attr="disabled" wire:target="_removeUpload"
                class="absolute inset-0 size-full cursor-pointer opacity-0 disabled:cursor-not-allowed" />
            <flux:icon.cloud-arrow-up variant="solid" class="size-7 shrink-0 text-zinc-400 dark:text-zinc-500" />
            <div class="min-w-0">
                <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $multiple ? 'Arraste os arquivos ou clique para selecionar' : 'Arraste o arquivo ou clique para selecionar' }}</p>
                <p id="{{ $uploadId }}-description" class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $description ?: ($multiple ? 'Selecione um ou mais arquivos.' : 'Selecione um arquivo.') }}</p>
            </div>
        </div>

        <div x-cloak x-show="uploading" class="space-y-3" role="status">
            <template x-for="(file, index) in pendingFiles" :key="index">
                <div class="flex min-w-0 items-center gap-3 text-sm text-zinc-600 dark:text-zinc-300">
                    <flux:icon.document variant="mini" class="shrink-0" />
                    <span class="min-w-0 flex-1 truncate" x-text="file.name"></span>
                    <span class="shrink-0" x-text="formatSize(file.size)"></span>
                </div>
            </template>
            <div class="flex items-center gap-3">
                <progress max="100" x-bind:value="progress" aria-label="Progresso do envio" class="h-1.5 min-w-0 flex-1 accent-zinc-700 dark:accent-zinc-200"></progress>
                <span class="text-sm text-zinc-500 dark:text-zinc-400" x-text="`${progress}%`"></span>
                <flux:button type="button" size="sm" variant="ghost" x-on:click="$wire.$cancelUpload(@js($model))">Cancelar envio</flux:button>
            </div>
        </div>

        @foreach ($selectedFiles as $file)
            <div wire:key="{{ $uploadId }}-{{ $file->getFilename() }}" class="flex min-w-0 items-center gap-3 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2.5 dark:border-zinc-700 dark:bg-white/5">
                @if ($preview && str_starts_with((string) $file->getMimeType(), 'image/') && $file->isPreviewable())
                    <img src="{{ $file->temporaryUrl() }}" alt="" class="size-10 shrink-0 rounded object-cover" />
                @else
                    <flux:icon.document variant="solid" class="size-6 shrink-0 text-zinc-400" />
                @endif
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100" title="{{ $file->getClientOriginalName() }}">{{ $file->getClientOriginalName() }}</p>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400" x-text="formatSize(@js($file->getSize()))"></p>
                </div>
                <flux:button type="button" variant="ghost" size="sm" icon="x-mark" :disabled="$disabled"
                    x-bind:disabled="uploading || @js((bool) $disabled)"
                    wire:click="$removeUpload(@js($model), @js($file->getFilename()))"
                    wire:loading.attr="disabled" wire:target="_removeUpload"
                    :aria-label="'Remover arquivo '.$file->getClientOriginalName()" />
            </div>
        @endforeach

        <div id="{{ $uploadId }}-error" x-cloak x-show="uploadError || @js((bool) $invalid)">
            <p x-show="uploadError" x-text="uploadError" role="alert" class="text-sm text-red-600 dark:text-red-400"></p>
            @if ($model)
                <flux:error :name="$model" />
            @endif
        </div>
    </div>
</flux:field>
