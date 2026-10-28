@props(['affiliations', 'currentAffiliation'])

<flux:dropdown position="bottom" align="start">
    <flux:sidebar.profile
        :name="auth()->user()->name"
        :initials="auth()->user()->initials()"
        icon:trailing="chevrons-up-down"
        data-test="sidebar-menu-button"
    />

    <flux:menu class="w-80 max-w-[calc(100vw-2rem)]">
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
        <flux:menu.separator />
        <div class="px-3 py-2">
            <flux:text size="sm" class="font-medium text-zinc-500 dark:text-zinc-400">Contexto atual</flux:text>
            <x-affiliations.active-context :affiliation="$currentAffiliation" />
        </div>
        <flux:menu.separator />
        <flux:menu.radio.group>
            <x-affiliations.switcher :affiliations="$affiliations" :current-affiliation="$currentAffiliation" />
            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                Configurações
            </flux:menu.item>
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
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
