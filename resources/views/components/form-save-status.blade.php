@props(['savedAt' => null, 'initialDirty' => false])

<div wire:ignore data-form-save-status data-initial-dirty="{{ $initialDirty ? 'true' : 'false' }}" data-saved-at="{{ $savedAt ? formatDateTime($savedAt) : '' }}"
    role="status" aria-live="polite" aria-atomic="true"
    {{ $attributes->class('flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-600 data-[state=dirty]:border-amber-300 data-[state=dirty]:bg-amber-50 data-[state=dirty]:text-amber-900 dark:border-zinc-700 dark:bg-zinc-900/50 dark:text-zinc-300 dark:data-[state=dirty]:border-amber-700 dark:data-[state=dirty]:bg-amber-950/30 dark:data-[state=dirty]:text-amber-200') }}>
    <span data-form-save-message class="font-medium">{{ $initialDirty ? 'Alterações não salvas' : 'Nenhuma alteração pendente nesta tela' }}</span>
    <span data-form-save-time>{{ $savedAt ? 'Última gravação: '.formatDateTime($savedAt) : '' }}</span>
</div>
