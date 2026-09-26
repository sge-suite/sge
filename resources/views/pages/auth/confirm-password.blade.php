<x-layouts::auth title="Confirmar senha">
    <div class="flex flex-col gap-6">
        @php
            $previousUrl = url()->previous(route('dashboard'));
            $previousUrlParts = parse_url($previousUrl);
            $previousScheme = is_array($previousUrlParts) ? ($previousUrlParts['scheme'] ?? null) : null;
            $previousHost = is_array($previousUrlParts) ? ($previousUrlParts['host'] ?? null) : null;
            $previousPort = is_array($previousUrlParts)
                ? ($previousUrlParts['port'] ?? ($previousScheme === 'https' ? 443 : 80))
                : null;
            $previousPath = is_array($previousUrlParts) ? ($previousUrlParts['path'] ?? null) : null;
            $currentPath = request()->getPathInfo();
            $sameOrigin = $previousScheme === request()->getScheme()
                && $previousHost === request()->getHost()
                && $previousPort === request()->getPort();
            $backUrl = $sameOrigin && rtrim($previousPath ?? '', '/') !== rtrim($currentPath, '/')
                ? $previousUrl
                : route('dashboard');
        @endphp

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

        <div class="flex justify-center">
            <flux:button variant="ghost" :href="$backUrl" icon="arrow-left" wire:navigate data-test="confirm-password-back">
                Voltar
            </flux:button>
        </div>
    </div>
</x-layouts::auth>
