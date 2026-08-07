<x-layouts::auth title="Confirmar senha">
    <div class="flex flex-col gap-6">
        <x-auth-header
            title="Confirmar senha"
            description="Esta é uma área segura do aplicativo. Por favor, confirme sua senha antes de continuar."
        />

        <x-auth-session-status class="text-center" :status="session('status')" />


        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="password"
                label="Senha"
                type="password"
                required
                autocomplete="current-password"
                placeholder="Senha"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="confirm-password-button">
                Confirmar
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
