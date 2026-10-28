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

                @can('viewAny', \App\Models\InternshipRequest::class)
                    <flux:sidebar.item icon="clipboard-document-list" :href="route('internship-requests.index')" :current="request()->routeIs('internship-requests.*')" wire:navigate>Minhas solicitações</flux:sidebar.item>
                @endcan

                @can('viewOwnAny', \App\Models\Internship::class)
                    <flux:sidebar.item icon="briefcase" :href="route('student-internships.index')" :current="request()->routeIs('student-internships.*', 'internships.show')" wire:navigate>Meus estágios</flux:sidebar.item>
                @endcan

                @can('viewReviewList', \App\Models\InternshipRequest::class)
                    <flux:sidebar.item icon="clipboard-document-check" :href="route('internship-review.index')" :current="request()->routeIs('internship-review.*')" wire:navigate>Solicitações de estágio</flux:sidebar.item>
                @endcan

                @can('viewAny', \App\Models\Internship::class)
                    <flux:sidebar.item icon="briefcase" :href="route('internships.index')" :current="request()->routeIs('internships.*')" wire:navigate>Estágios</flux:sidebar.item>
                @endcan

                @can('viewAny', \App\Models\SupervisorEvaluation::class)
                    <flux:sidebar.item icon="clipboard-document-check" :href="route('supervisor-evaluations.index')" :current="request()->routeIs('supervisor-evaluations.*')" wire:navigate>Avaliações do supervisor</flux:sidebar.item>
                @endcan

                @if (auth()->user()->can('viewAdministration', \App\Models\Campus::class)
                    || auth()->user()->can('viewOwn', \App\Models\Campus::class)
                    || auth()->user()->can('viewAny', \App\Models\User::class)
                    || auth()->user()->can('viewAny', \App\Models\Course::class)
                    || auth()->user()->can('viewAny', \App\Models\InternshipType::class)
                    || auth()->user()->can('viewAny', \App\Models\GrantingParty::class)
                    || auth()->user()->can('viewAny', \App\Models\Holiday::class))
                    <flux:sidebar.group heading="Configurações">
                        @can('viewAdministration', \App\Models\Campus::class)
                            <flux:sidebar.item icon="building-office-2" :href="route('campuses.index')" :current="request()->routeIs('campuses.*')" wire:navigate>
                                Campi
                            </flux:sidebar.item>
                        @endcan
                        @can('viewOwn', \App\Models\Campus::class)
                            <flux:sidebar.item icon="building-office-2" :href="route('campuses.own')" :current="request()->routeIs('campuses.own*')" wire:navigate>Meu campus</flux:sidebar.item>
                        @endcan
                        @can('viewAny', \App\Models\User::class)
                            <flux:sidebar.item icon="users" :href="route('users.index')" :current="request()->routeIs('users.*')" wire:navigate>Usuários</flux:sidebar.item>
                        @endcan
                        @can('viewAny', \App\Models\Course::class)
                            <flux:sidebar.item icon="academic-cap" :href="route('courses.index')" :current="request()->routeIs('courses.*')" wire:navigate>Cursos</flux:sidebar.item>
                        @endcan
                        @can('viewAny', \App\Models\InternshipType::class)
                            <flux:sidebar.item icon="briefcase" :href="route('internship-types.index')" :current="request()->routeIs('internship-types.*')" wire:navigate>Tipos de estágio</flux:sidebar.item>
                        @endcan
                        @can('viewAny', \App\Models\GrantingParty::class)
                            <flux:sidebar.item icon="building-office" :href="route('granting-parties.index')" :current="request()->routeIs('granting-parties.*')" wire:navigate>Partes concedentes</flux:sidebar.item>
                        @endcan
                        @can('viewAny', \App\Models\DocumentTemplate::class)
                            <flux:sidebar.item icon="document-text" :href="route('document-templates.index')" :current="request()->routeIs('document-templates.*')" wire:navigate>Templates DOCX</flux:sidebar.item>
                        @endcan
                        @can('viewAny', \App\Models\Holiday::class)
                            <flux:sidebar.item icon="tent-tree" :href="route('holidays.index')" :current="request()->routeIs('holidays.*', 'holiday-recurrences.*', 'holiday-imports.*')" wire:navigate>Feriados</flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endif

                @if (auth()->user()->can('viewAny', \Spatie\Activitylog\Models\Activity::class) || auth()->user()->can('viewAny', \App\Models\EmailDeliveryAttempt::class))
                    <flux:sidebar.group heading="Logs">
                        @can('viewAny', \Spatie\Activitylog\Models\Activity::class)
                            <flux:sidebar.item icon="clipboard-document-list" :href="route('audit.index')" :current="request()->routeIs('audit.*')" wire:navigate>Auditoria</flux:sidebar.item>
                        @endcan
                        @can('viewAny', \App\Models\EmailDeliveryAttempt::class)
                            <flux:sidebar.item icon="envelope" :href="route('email-logs.index')" :current="request()->routeIs('email-logs.*')" wire:navigate>E-mails</flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" :affiliations="$navigationAffiliations" :current-affiliation="$activeNavigationAffiliation" />
        </flux:sidebar>

        <flux:header class="h-auto! min-h-16 gap-3 border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <div class="shrink-0 lg:hidden">
                <flux:dropdown position="top" align="end">
                    <flux:profile
                        :initials="auth()->user()->initials()"
                        icon-trailing="chevron-down"
                    />

                    <flux:menu class="w-80 max-w-[calc(100vw-2rem)]">
                        <flux:menu.radio.group>
                            <div class="p-0 text-sm font-normal">
                                <div class="flex min-w-0 items-center gap-2 px-1 py-1.5 text-start text-sm">
                                    <flux:avatar
                                        :name="auth()->user()->name"
                                        :initials="auth()->user()->initials()"
                                    />

                                    <div class="grid min-w-0 flex-1 text-start text-sm leading-tight">
                                        <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                        <flux:text class="wrap-anywhere">E-mail de acesso: {{ auth()->user()->email }}</flux:text>
                                    </div>
                                </div>
                            </div>
                        </flux:menu.radio.group>

                        <flux:menu.separator />

                        <div class="px-3 py-2">
                            <flux:text size="sm" class="font-medium text-zinc-500 dark:text-zinc-400">Contexto atual</flux:text>
                            <x-affiliations.active-context :affiliation="$activeNavigationAffiliation" />
                        </div>

                        <flux:menu.separator />

                        <flux:menu.radio.group>
                            <x-affiliations.switcher :affiliations="$navigationAffiliations" :current-affiliation="$activeNavigationAffiliation" />
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
                                class="w-full cursor-pointer text-red-500! dark:text-red-300! data-active:text-red-600! dark:data-active:text-red-400! **:data-flux-menu-item-icon:text-red-400! dark:**:data-flux-menu-item-icon:text-red-300! [&[data-active]_[data-flux-menu-item-icon]]:text-red-600! dark:[&[data-active]_[data-flux-menu-item-icon]]:text-red-400!"
                                data-test="logout-button"
                            >
                                Sair
                            </flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            </div>
        </flux:header>

        {{ $slot }}

        @if (session('affiliation_switched'))
            <div hidden x-data x-init="$nextTick(() => $flux.toast(@js(session('affiliation_switched')), { variant: 'success' }))"></div>
        @endif

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
