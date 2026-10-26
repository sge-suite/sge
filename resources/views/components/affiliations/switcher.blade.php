@props(['affiliations', 'currentAffiliation'])

@php
    $affiliationCount = $affiliations->count();
    $destination = $currentAffiliation === null ? null : $affiliations->first(fn ($affiliation): bool => $affiliation->id !== $currentAffiliation->id);
@endphp

@if ($affiliationCount === 2 && $destination !== null)
    <form method="POST" action="{{ route('affiliations.switch') }}" class="w-full" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false">
        @csrf
        <flux:menu.item as="button" type="submit" icon="link" class="h-auto! w-full whitespace-normal! py-2!" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
            <span class="block min-w-0 max-w-xs text-start">
                <span class="block font-medium">Trocar para {{ $destination->type->label() }}</span>
                <span class="mt-1 block text-xs leading-relaxed wrap-anywhere opacity-75">{{ $destination->contextLabel() }}</span>
            </span>
        </flux:menu.item>
    </form>
@elseif ($affiliationCount > 1)
    <flux:menu.item :href="route('affiliations.select')" icon="link" wire:navigate>
        Trocar vínculo
    </flux:menu.item>
@endif
