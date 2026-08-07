<x-layouts::auth title="Entrar">
    <div class="flex flex-col gap-6">
        <x-auth-header title="Entre na sua conta" description="Digite seu e-mail e senha abaixo para entrar" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />


        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                label="Endereço de e-mail"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    label="Senha"
                    type="password"
                    required
                    autocomplete="current-password"
                    placeholder="Senha"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        Esqueceu sua senha?
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" label="Lembrar de mim" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    Entrar
                </flux:button>
            </div>
        </form>

    </div>
</x-layouts::auth>
