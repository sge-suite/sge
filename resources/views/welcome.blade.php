<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen bg-zinc-50 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 flex flex-col font-sans antialiased selection:bg-accent/20">

    <!-- Main Content (Hero) -->
    <main class="flex-1 flex items-center justify-center p-6 w-full">
        <div class="max-w-4xl mx-auto w-full text-center space-y-8">
            
            <div class="inline-flex items-center justify-center mb-6">
                <x-app-logo-icon class="size-24 text-accent dark:text-accent-content" />
            </div>

            <h1 class="text-4xl md:text-6xl font-bold tracking-tight text-zinc-900 dark:text-white">
                Sistema de <span class="text-accent">Gestão de Estágios</span>
            </h1>
            
            <p class="text-lg text-zinc-500 dark:text-zinc-400 max-w-2xl mx-auto">
                Plataforma unificada para simplificar e centralizar todo o fluxo de estágios.
            </p>

            <div class="flex flex-col sm:flex-row items-center justify-center gap-4 pt-2 pb-6">
                @auth
                    <flux:button variant="primary" href="{{ route('dashboard') }}" wire:navigate class="w-full sm:w-auto">
                        Dashboard
                    </flux:button>
                @else
                    <flux:button variant="primary" href="{{ route('login') }}" wire:navigate class="w-full sm:w-auto">
                        Fazer Login no Sistema
                    </flux:button>
                @endauth
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-left mt-8">
                <flux:card class="flex flex-col gap-3">
                    <div class="size-10 rounded-lg bg-accent/10 flex items-center justify-center text-accent">
                        <flux:icon name="document-text" class="size-5" />
                    </div>
                    <flux:heading size="lg">Solicitações Simplificadas</flux:heading>
                    <flux:text class="text-sm">Inicie o processo de estágio via formulários digitais e acompanhe cada etapa da aprovação online.</flux:text>
                </flux:card>
                
                <flux:card class="flex flex-col gap-3">
                    <div class="size-10 rounded-lg bg-accent/10 flex items-center justify-center text-accent">
                        <flux:icon name="folder-open" class="size-5" />
                    </div>
                    <flux:heading size="lg">Gestão de Documentos</flux:heading>
                    <flux:text class="text-sm">Centralize a geração, coleta de assinaturas e armazenamento de termos, planos e relatórios.</flux:text>
                </flux:card>

                <flux:card class="flex flex-col gap-3">
                    <div class="size-10 rounded-lg bg-accent/10 flex items-center justify-center text-accent">
                        <flux:icon name="chart-pie" class="size-5" />
                    </div>
                    <flux:heading size="lg">Acompanhamento Ativo</flux:heading>
                    <flux:text class="text-sm">Monitore prazos, avaliações e o andamento geral dos vínculos com painéis de controle claros.</flux:text>
                </flux:card>
            </div>
        </div>
    </main>

    @fluxScripts
</body>
</html>
