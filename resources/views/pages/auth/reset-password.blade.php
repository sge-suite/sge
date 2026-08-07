<x-layouts::auth title="Redefinir senha">
    <div class="flex flex-col gap-6">
        <x-auth-header title="Redefinir senha" description="Por favor, digite sua nova senha abaixo" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Token -->
            <input type="hidden" name="token" value="{{ request()->route('token') }}">

            <!-- Email Address -->
            <flux:input
                name="email"
                value="{{ request('email') }}"
                label="E-mail"
                type="email"
                required
                autocomplete="email"
            />

            <!-- Password -->
            <flux:input
                name="password"
                label="Senha"
                type="password"
                required
                autocomplete="new-password"
                placeholder="Senha"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                label="Confirmar senha"
                type="password"
                required
                autocomplete="new-password"
                placeholder="Confirmar senha"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="reset-password-button">
                    Redefinir senha
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts::auth>
