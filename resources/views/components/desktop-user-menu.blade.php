<flux:dropdown position="bottom" align="start">
    <flux:sidebar.profile
        :name="auth()->user()->name"
        :initials="auth()->user()->initials()"
        icon:trailing="chevrons-up-down"
        data-test="sidebar-menu-button"
    />

    <flux:menu>
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
        <flux:menu.separator />
        <flux:menu.radio.group>
            @if (auth()->user()->affiliations()->active()->count() > 1)
                <flux:menu.item :href="route('affiliations.select')" icon="arrows-right-left" wire:navigate>
                    Trocar vínculo
                </flux:menu.item>
            @endif
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
                    class="w-full cursor-pointer !text-red-500 dark:!text-red-300 data-active:!text-red-600 dark:data-active:!text-red-400 **:data-flux-menu-item-icon:!text-red-400 dark:**:data-flux-menu-item-icon:!text-red-300 [&[data-active]_[data-flux-menu-item-icon]]:!text-red-600 dark:[&[data-active]_[data-flux-menu-item-icon]]:!text-red-400"
                    data-test="logout-button"
                >
                    Sair
                </flux:menu.item>
            </form>
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
