@php($affiliationCount = auth()->user()->affiliations()->active()->count())

@if ($affiliationCount === 2)
    <form method="POST" action="{{ route('affiliations.switch') }}" class="w-full" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false">
        @csrf
        <flux:menu.item as="button" type="submit" icon="link" class="w-full" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
            Trocar vínculo
        </flux:menu.item>
    </form>
@elseif ($affiliationCount > 2)
    <flux:menu.item :href="route('affiliations.select')" icon="link" wire:navigate>
        Trocar vínculo
    </flux:menu.item>
@endif
