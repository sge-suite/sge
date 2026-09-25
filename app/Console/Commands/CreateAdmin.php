<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Prompts\Prompt;
use LaravelLegends\PtBrValidator\Rules\Cpf;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('admin:create')]
#[Description('Cria a primeira conta Administrador do Sistema.')]
class CreateAdmin extends Command
{
    use PasswordValidationRules;

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Este comando precisa de um terminal interativo.');

            return self::FAILURE;
        }

        if ($this->hasActiveSystemAdministrator()) {
            $this->error('Já existe um Administrador do Sistema ativo.');

            return self::FAILURE;
        }

        try {
            $name = $this->askText(
                'Nome completo',
                'name',
                ['required', 'string', 'max:255'],
                transform: static fn (string $value): string => trim($value),
            );
            $cpf = $this->askText(
                'CPF (11 dígitos, somente números)',
                'cpf',
                ['required', 'string', 'regex:/^[0-9]{11}$/', new Cpf, Rule::unique(User::class, 'cpf')],
            );
            $email = $this->askEmail();
            $registrationNumber = $this->askText(
                'Número de registro institucional',
                'registration_number',
                ['required', 'string', 'max:255'],
                transform: static fn (string $value): string => trim($value),
            );
            $password = $this->askPassword();

            $this->newLine();
            $this->table(['Campo', 'Valor'], [
                ['Nome', $name],
                ['CPF', $cpf],
                ['E-mail da conta e do vínculo', $email],
                ['Número de registro', $registrationNumber],
                ['Vínculo', AffiliationType::SystemAdministrator->label()],
            ]);

            if (! confirm('Criar esta conta e seu vínculo administrador?', default: false)) {
                $this->comment('Criação cancelada. Nenhum registro foi criado.');

                return self::FAILURE;
            }

            DB::transaction(function () use ($name, $cpf, $email, $registrationNumber, $password): void {
                if ($this->hasActiveSystemAdministrator()) {
                    throw new RuntimeException('Já existe um Administrador do Sistema ativo.');
                }

                $user = User::query()->create([
                    'name' => $name,
                    'cpf' => $cpf,
                    'email' => $email,
                    'password' => $password,
                ]);

                $user->affiliations()->create([
                    'campus_id' => null,
                    'course_id' => null,
                    'type' => AffiliationType::SystemAdministrator,
                    'registration_number' => $registrationNumber,
                    'email' => $email,
                ]);
            });
        } catch (Throwable) {
            $this->error('Não foi possível criar a conta e o vínculo. Nenhum registro foi mantido.');

            return self::FAILURE;
        }

        $this->info('Conta Administrador do Sistema criada com sucesso.');

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

    private function askPassword(): string
    {
        while (true) {
            $passwordValue = password('Senha inicial', required: true);
            $confirmation = password('Confirme a senha', required: true);
            $validator = Validator::make([
                'password' => $passwordValue,
                'password_confirmation' => $confirmation,
            ], [
                'password' => $this->passwordRules(),
            ]);

            if ($validator->passes()) {
                return $passwordValue;
            }

            $this->error($validator->errors()->first('password'));
        }
    }

    private function askEmail(): string
    {
        return text(
            'E-mail',
            required: true,
            validate: function (string $value): ?string {
                $email = mb_strtolower(trim($value));
                $error = $this->validationError('email', $email, ['required', 'string', 'email', 'max:255']);

                if ($error !== null) {
                    return $error;
                }

                return User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()
                    ? 'Este e-mail já está cadastrado.'
                    : null;
            },
            transform: static fn (string $value): string => mb_strtolower(trim($value)),
        );
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

    private function hasActiveSystemAdministrator(): bool
    {
        return Affiliation::query()
            ->where('type', AffiliationType::SystemAdministrator->value)
            ->active()
            ->exists();
    }
}
