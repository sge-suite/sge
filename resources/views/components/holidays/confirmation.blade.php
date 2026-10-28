@props(['target', 'operation', 'kind'])

<flux:modal name="calendar-confirmation" wire:model.self="showConfirmation" class="md:w-md">
    @if ($target)
        <form method="POST" action="{{ route($kind.'.'.($operation === 'delete' ? 'destroy' : 'restore'), $target) }}" class="flex flex-col gap-6"
            x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false">
            @csrf
            @method($operation === 'delete' ? 'DELETE' : 'PATCH')
            <div>
                <flux:heading size="lg" level="2">{{ $operation === 'delete' ? 'Mover para a lixeira' : 'Restaurar cadastro' }}</flux:heading>
                <flux:text class="mt-2">{{ $target->name }}@if ($kind === 'holidays') · {{ formatShort($target->date) }}@endif</flux:text>
                <flux:text class="mt-2">
                    @if ($kind === 'holidays')
                        {{ $operation === 'delete' ? 'Este feriado deixará de valer no calendário. Você poderá restaurá-lo pela lixeira.' : 'Este feriado voltará a valer no calendário.' }}
                    @else
                        {{ $operation === 'delete' ? 'Novas datas deixarão de ser geradas. As datas já cadastradas serão mantidas e você poderá restaurar esta recorrência pela lixeira.' : 'A geração de datas será retomada. Feriados excluídos continuam na lixeira e ajustes manuais serão preservados.' }}
                    @endif
                </flux:text>
            </div>
            <div class="flex flex-wrap justify-end gap-3">
                <flux:modal.close><flux:button type="button" variant="outline" wire:click="closeConfirmation">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" :variant="$operation === 'delete' ? 'danger' : 'primary'" :loading="false" x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                    <flux:icon.loading x-show="submitting" x-cloak class="size-4" />{{ $operation === 'delete' ? 'Mover para a lixeira' : 'Restaurar' }}
                </flux:button>
            </div>
        </form>
    @endif
</flux:modal>
