@props([
    'code',
    'title',
    'message',
    'actionRoute' => 'home',
    'actionLabel' => 'Ir ao início',
])

@php
    $actionUrl = route($actionRoute, absolute: false);

    try {
        $stylesheet = \Illuminate\Support\Facades\Vite::asset('resources/css/app.css');
        $fonts = \Illuminate\Support\Facades\Vite::fonts();
    } catch (\Illuminate\Foundation\ViteException) {
        $stylesheet = null;
    }
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark auth-shell auth-card-shell">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>{{ $title }} - {{ config('app.name', 'SGE') }}</title>
        <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
        @if ($stylesheet !== null)
            {{ $fonts }}
            <link rel="stylesheet" href="{{ $stylesheet }}" />
            @fluxAppearance
        @endif
    </head>
    <body class="min-h-screen bg-neutral-100 antialiased dark:bg-neutral-950">
        <main class="flex min-h-svh items-center justify-center p-6 md:p-10" aria-labelledby="error-title">
            <div class="flex w-full max-w-md flex-col items-center gap-6">
                <x-app-logo-icon class="size-16 text-brand" width="64" height="64" aria-hidden="true" />

                <section class="flex w-full flex-col items-center gap-6 rounded-xl border bg-white px-6 py-8 text-center shadow-xs sm:px-10 dark:border-stone-800 dark:bg-stone-950">
                    <div class="flex flex-col gap-2">
                        <flux:text class="font-semibold text-brand">Erro {{ $code }}</flux:text>
                        <flux:heading id="error-title" level="1" size="xl">{{ $title }}</flux:heading>
                        <flux:text>{{ $message }}</flux:text>
                    </div>

                    @unless (request()->getPathInfo() === $actionUrl)
                        <flux:button variant="primary" :href="$actionUrl">
                            {{ $actionLabel }}
                        </flux:button>
                    @endunless
                </section>
            </div>
        </main>
    </body>
</html>
