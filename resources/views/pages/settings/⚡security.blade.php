<?php

use App\Actions\RequestEmailDelivery;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Support\ActiveAffiliationContext;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Activitylog\Support\CauserResolver;

new #[Title('Segurança')] class extends Component {
    use PasswordValidationRules;
    use ProfileValidationRules;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $email = '';
    public string $email_confirmation = '';
    public string $email_current_password = '';

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: 'Senha atualizada.');
    }

    public function updateEmail(RequestEmailDelivery $requestEmailDelivery, ActiveAffiliationContext $context, CauserResolver $causerResolver): void
    {
        $user = Auth::user();
        $affiliation = $context->currentFor($user, app('session.store'));

        abort_if($affiliation === null, 403, 'Selecione um vínculo ativo antes de alterar o e-mail.');

        $this->email = mb_strtolower(trim($this->email));
        $this->email_confirmation = mb_strtolower(trim($this->email_confirmation));

        try {
            $validated = $this->validate([
                'email' => $this->emailRules($user->id),
                'email_confirmation' => ['required', 'string', 'same:email'],
                'email_current_password' => $this->currentPasswordRules(),
            ], [
                'email_confirmation.required' => 'Confirme o novo e-mail.',
                'email_confirmation.same' => 'Os e-mails informados não coincidem.',
            ]);

            if ($validated['email'] === $user->email) {
                Flux::toast(text: 'O e-mail da conta não foi alterado.');

                return;
            }

            $previousEmail = $user->email;

            $causerResolver->withCauser($affiliation, function () use ($user, $validated, $previousEmail, $requestEmailDelivery): void {
                DB::transaction(function () use ($user, $validated, $previousEmail, $requestEmailDelivery): void {
                    $user->update(['email' => $validated['email']]);
                    $requestEmailDelivery->accountEmailChanged($previousEmail, $previousEmail, $validated['email'], (string) Str::uuid());
                    $requestEmailDelivery->accountEmailChanged($validated['email'], $previousEmail, $validated['email'], (string) Str::uuid());
                });
            });

            $this->reset('email', 'email_confirmation');

            Flux::toast(variant: 'success', text: 'E-mail da conta atualizado. Enviaremos avisos aos endereços antigo e novo.');
        } finally {
            $this->reset('email_current_password');
        }
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">Configurações de Segurança</flux:heading>

    <x-pages::settings.layout heading="Segurança" subheading="Gerencie a senha e o e-mail de acesso à sua conta" :wide="true">
        <div class="grid gap-10 xl:grid-cols-2 xl:gap-12">
            <section class="border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="password-settings-heading">
                <flux:heading id="password-settings-heading" level="2" size="lg">Alterar senha</flux:heading>
                <flux:text class="mt-2">Use uma senha diferente da atual para proteger sua conta.</flux:text>

                <form method="POST" wire:submit="updatePassword" class="mt-6 space-y-5">
                    <flux:input
                        wire:model="current_password"
                        label="Senha atual"
                        type="password"
                        required
                        autocomplete="current-password"
                        viewable
                    />
                    <flux:input
                        wire:model="password"
                        label="Nova senha"
                        type="password"
                        required
                        autocomplete="new-password"
                        passwordrules="{{ \Illuminate\Validation\Rules\Password::min(8)->max(64)->letters()->mixedCase()->numbers()->symbols()->toPasswordRulesString() }}"
                        viewable
                    />
                    <div wire:key="password-rules-{{ $errors->has('password') ? 'invalid' : 'ready' }}">
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
                    </div>
                    <flux:input
                        wire:model="password_confirmation"
                        label="Confirmar nova senha"
                        type="password"
                        required
                        autocomplete="new-password"
                        passwordrules="{{ \Illuminate\Validation\Rules\Password::min(8)->max(64)->letters()->mixedCase()->numbers()->symbols()->toPasswordRulesString() }}"
                        viewable
                    />

                    <flux:button variant="primary" type="submit" data-test="update-password-button">
                        Alterar senha
                    </flux:button>
                </form>
            </section>

            <section class="border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="email-settings-heading">
                <flux:heading id="email-settings-heading" level="2" size="lg">Alterar e-mail da conta</flux:heading>
                <flux:text class="mt-2">
                    O novo endereço será usado para entrar e recuperar a senha. Os e-mails dos seus vínculos não mudam.
                </flux:text>
                <flux:text class="mt-3 text-sm">E-mail atual: <span class="break-all font-medium text-zinc-900 dark:text-zinc-100">{{ Auth::user()->email }}</span></flux:text>

                <form method="POST" wire:submit="updateEmail" class="mt-6 space-y-5">
                    <flux:input
                        wire:model="email"
                        label="Novo e-mail"
                        type="email"
                        required
                        autocomplete="email"
                    />
                    <flux:input
                        wire:model="email_confirmation"
                        label="Confirmar novo e-mail"
                        type="email"
                        required
                        autocomplete="off"
                    />
                    <flux:input
                        wire:model="email_current_password"
                        label="Senha atual"
                        type="password"
                        required
                        autocomplete="current-password"
                        viewable
                    />
                    <flux:text class="text-sm">Enviaremos avisos ao endereço atual e ao novo endereço.</flux:text>
                    <flux:button variant="primary" type="submit" data-test="update-account-email-button">
                        Alterar e-mail
                    </flux:button>
                </form>
            </section>
        </div>
    </x-pages::settings.layout>
</section>
