@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="Gestão de Estágios" {{ $attributes }}>
        <x-slot name="logo" class="flex items-center justify-center text-brand">
            <x-app-logo-icon class="size-8 fill-current" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'SGE')" {{ $attributes }}>
        <x-slot name="logo" class="flex items-center justify-center text-brand">
            <x-app-logo-icon class="size-8 fill-current" />
        </x-slot>
    </flux:brand>
@endif
