<x-layouts::auth title="Esqueceu a senha?">
    <div class="flex flex-col gap-6">
        <x-auth-header title="Esqueceu a senha?" description="Digite seu e-mail para receber um link de redefinição de senha" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                label="Endereço de e-mail"
                type="email"
                required
                autofocus
                placeholder="email@example.com"
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="email-password-reset-link-button">
                Enviar link de redefinição
            </flux:button>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-400">
            <span>Ou, retorne para</span>
            <flux:link :href="route('login')" wire:navigate>Login</flux:link>
        </div>
    </div>
</x-layouts::auth>
