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
                passwordrules="{{ \Illuminate\Validation\Rules\Password::min(8)->max(64)->letters()->mixedCase()->numbers()->symbols()->toPasswordRulesString() }}"
                viewable
            />

            <x-accordion title="Regras para senha" :open="$errors->has('password')">
                <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">A senha deve:</p>
                <ul class="mt-2 list-disc space-y-1 ps-5 text-sm text-zinc-600 dark:text-zinc-300">
                    <li>Ter entre 8 e 64 caracteres.</li>
                    <li>Conter letras maiúsculas e minúsculas.</li>
                    <li>Conter pelo menos um número.</li>
                    <li>Conter pelo menos um símbolo.</li>
                    <li>Não ter sido exposta em vazamentos de dados conhecidos.</li>
                </ul>
            </x-accordion>

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                label="Confirmar senha"
                type="password"
                required
                autocomplete="new-password"
                placeholder="Confirmar senha"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::min(8)->max(64)->letters()->mixedCase()->numbers()->symbols()->toPasswordRulesString() }}"
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
