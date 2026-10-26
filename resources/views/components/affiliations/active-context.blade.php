@props(['affiliation'])

<div {{ $attributes->class('min-w-0 flex-1 py-3') }}>
    @if ($affiliation !== null)
        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Vínculo ativo · #{{ $affiliation->id }}</p>
        <p class="mt-0.5 text-sm font-semibold wrap-anywhere text-zinc-900 dark:text-zinc-100">
            {{ $affiliation->type->label() }} · {{ $affiliation->campus_id === null ? 'Escopo global' : $affiliation->campus?->name }}
        </p>
        <div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-zinc-600 dark:text-zinc-300">
            @if (filled($affiliation->course?->name))<span class="wrap-anywhere">{{ $affiliation->course->name }}</span>@endif
            @if (filled($affiliation->registration_number))<span class="wrap-anywhere">Matrícula {{ $affiliation->registration_number }}</span>@endif
            @if (filled($affiliation->email))<span class="wrap-anywhere">E-mail do vínculo: {{ $affiliation->email }}</span>@endif
        </div>
    @else
        <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Selecione um vínculo para continuar.</p>
    @endif
</div>
