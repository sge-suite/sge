<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark app-shell">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    Painel
                </flux:sidebar.item>
                @can('viewAdministration', \App\Models\Campus::class)
                    <flux:sidebar.item icon="building-office-2" :href="route('campuses.index')" :current="request()->routeIs('campuses.*')" wire:navigate>
                        Campi
                    </flux:sidebar.item>
                    @can('viewAny', \App\Models\User::class)
                        <flux:sidebar.item icon="users" :href="route('users.index')" :current="request()->routeIs('users.*')" wire:navigate>Usuários</flux:sidebar.item>
                    @endcan
                    @can('viewAny', \Spatie\Activitylog\Models\Activity::class)
                        <flux:sidebar.item icon="clipboard-document-list" :href="route('audit.index')" :current="request()->routeIs('audit.*')" wire:navigate>Auditoria</flux:sidebar.item>
                    @endcan
                @endcan
                @can('viewAny', \App\Models\EmailDeliveryAttempt::class)
                    <flux:sidebar.item icon="envelope" :href="route('email-logs.index')" :current="request()->routeIs('email-logs.*')" wire:navigate>E-mails</flux:sidebar.item>
                @endcan
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        @if (auth()->user()->affiliations()->active()->count() > 1)
                            <flux:menu.item :href="route('affiliations.select')" icon="link" wire:navigate>
                                Trocar vínculo
                            </flux:menu.item>
                        @endif
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            Configurações
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            variant="danger"
                            class="w-full cursor-pointer !text-red-500 dark:!text-red-300 data-active:!text-red-600 dark:data-active:!text-red-400 **:data-flux-menu-item-icon:!text-red-400 dark:**:data-flux-menu-item-icon:!text-red-300 [&[data-active]_[data-flux-menu-item-icon]]:!text-red-600 dark:[&[data-active]_[data-flux-menu-item-icon]]:!text-red-400"
                            data-test="logout-button"
                        >
                            Sair
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
