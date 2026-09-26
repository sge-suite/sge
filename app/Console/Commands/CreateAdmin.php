<?php

namespace App\Console\Commands;

use App\Actions\RequestEmailDelivery;
use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LaravelLegends\PtBrValidator\Rules\Cpf;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

#[Signature('admin:create')]
#[Description('Cria um vínculo ativo de Administrador do Sistema.')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Este comando precisa de um terminal interativo.');

            return self::FAILURE;
        }

        try {
            $cpf = $this->askText(
                'CPF (11 dígitos, somente números)',
                'cpf',
                ['required', 'string', 'regex:/^[0-9]{11}$/', new Cpf],
            );
            $existingUser = User::query()->where('cpf', $cpf)->first();

            if ($existingUser === null) {
                $name = $this->askText(
                    'Nome completo',
                    'name',
                    ['required', 'string', 'max:255'],
                    transform: static fn (string $value): string => trim($value),
                );
                $accountEmail = $this->askEmail('E-mail da conta', checkUserUniqueness: true);
                $affiliationEmail = $accountEmail;
            } else {
                $name = $existingUser->name;
                $accountEmail = $existingUser->email;
                $affiliationEmail = $this->askEmail('E-mail do vínculo', checkUserUniqueness: false);
            }

            $registrationNumber = $this->askText(
                'Número de registro institucional',
                'registration_number',
                ['required', 'string', 'max:255'],
                transform: static fn (string $value): string => trim($value),
            );
            $this->newLine();
            $this->table(['Campo', 'Valor'], [
                ['Nome', $name],
                ['CPF', $cpf],
                ['E-mail da conta', $accountEmail],
                ['E-mail do vínculo', $affiliationEmail],
                ['Número de registro', $registrationNumber],
                ['Vínculo', AffiliationType::SystemAdministrator->label()],
            ]);

            $confirmation = $existingUser === null
                ? 'Criar esta conta e seu vínculo administrador?'
                : 'Adicionar o vínculo administrador a esta conta?';

            if (! confirm($confirmation, default: false)) {
                $this->comment('Criação cancelada. Nenhum registro foi criado.');

                return self::FAILURE;
            }

            DB::transaction(function () use ($existingUser, $name, $cpf, $accountEmail, $affiliationEmail, $registrationNumber): void {
                if ($existingUser === null) {
                    $user = Context::scope(
                        fn (): User => User::query()->create([
                            'name' => $name,
                            'cpf' => $cpf,
                            'email' => $accountEmail,
                            'password' => Str::password(64),
                        ]),
                        ['audit_actor' => 'terminal'],
                    );
                } else {
                    $user = $existingUser;
                }

                $affiliation = Context::scope(
                    fn (): Affiliation => $user->affiliations()->create([
                        'campus_id' => null,
                        'course_id' => null,
                        'type' => AffiliationType::SystemAdministrator,
                        'registration_number' => $registrationNumber,
                        'email' => $affiliationEmail,
                    ]),
                    ['audit_actor' => 'terminal'],
                );

                $emailDelivery = app(RequestEmailDelivery::class);

                if ($existingUser === null) {
                    $emailDelivery->accountCreated($user->email, (string) Str::uuid());

                    return;
                }

                foreach (array_unique([$user->email, $affiliation->email]) as $recipientEmail) {
                    $emailDelivery->affiliationCreated($recipientEmail, (string) Str::uuid());
                }
            });
        } catch (Throwable) {
            $this->error('Não foi possível criar o administrador. Nenhum novo registro foi mantido.');

            return self::FAILURE;
        }

        $this->info($existingUser === null
            ? 'Conta Administrador do Sistema criada com sucesso. O convite para definir a senha foi enfileirado.'
            : 'Vínculo de Administrador do Sistema adicionado. Os avisos foram enfileirados para a conta e o vínculo.');

        return self::SUCCESS;
    }

    /**
     * Prompt for a value and validate it before accepting the response.
     *
     * @param  array<int, mixed>  $rules
     */
    private function askText(string $label, string $attribute, array $rules, ?\Closure $transform = null): string
    {
        return text(
            $label,
            required: true,
            validate: fn (string $value): ?string => $this->validationError($attribute, $value, $rules),
            transform: $transform,
        );
    }

    private function askEmail(string $label, bool $checkUserUniqueness): string
    {
        $value = text(
            $label,
            required: true,
            validate: function (string $value) use ($checkUserUniqueness): ?string {
                $email = mb_strtolower(trim($value));
                $error = $this->validationError('email', $email, [
                    'required',
                    'string',
                    'email:rfc',
                    'regex:/^[^@\\s]+@[^@\\s.]+(?:\\.[^@\\s.]+)+$/',
                    'max:255',
                ]);

                if ($error !== null) {
                    return $error;
                }

                return $checkUserUniqueness && User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()
                    ? 'Este e-mail já está cadastrado.'
                    : null;
            },
            transform: static fn (string $value): string => mb_strtolower(trim($value)),
        );

        return mb_strtolower(trim($value));
    }

    /**
     * @param  array<int, mixed>  $rules
     */
    private function validationError(string $attribute, string $value, array $rules): ?string
    {
        $message = Validator::make([$attribute => $value], [$attribute => $rules])
            ->errors()
            ->first($attribute);

        return $message === '' ? null : $message;
    }
}
