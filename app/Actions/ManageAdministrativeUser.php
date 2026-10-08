<?php

namespace App\Actions;

use App\Enums\AffiliationType;
use App\Helpers\DigitsHelper;
use App\Models\Affiliation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LaravelLegends\PtBrValidator\Rules\Cpf;

class ManageAdministrativeUser
{
    public function __construct(private AdministrativeAffiliationTransaction $transaction, private RequestEmailDelivery $emailDelivery) {}

    /** @param array{name?: string, cpf?: string, email?: string, current_password?: string, confirmed?: mixed} $data */
    public function handle(User $actor, User $target, string $operation, array $data): User
    {
        abort_unless(in_array($operation, ['update', 'delete'], true), 404);

        return $this->transaction->handle($actor, function (?int $selectedId) use ($actor, $target, $operation, $data): User {
            $user = User::query()->lockForUpdate()->findOrFail($target->id);
            $affiliations = $user->affiliations()->orderBy('id')->lockForUpdate()->get();
            $this->transaction->authorizeActor($actor, $selectedId);
            Gate::forUser($actor)->authorize($operation, $user);

            if ($operation === 'update') {
                $data['email'] = mb_strtolower(trim($data['email']));
                $data['cpf'] = DigitsHelper::only($data['cpf']);
                $data['name'] = trim($data['name']);
                Validator::make($data, [
                    'name' => ['required', 'string', 'max:255'],
                    'cpf' => ['required', 'string', new Cpf, Rule::unique(User::class, 'cpf')->ignore($user)],
                    'email' => ['required', 'string', 'email', 'max:255'],
                ])->validate();
                if (User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->whereKeyNot($user->id)->exists()) {
                    throw ValidationException::withMessages(['email' => 'Este e-mail de login já está cadastrado.']);
                }
                $previousEmail = $user->email;
                $user->update(['name' => $data['name'], 'cpf' => $data['cpf'], 'email' => $data['email']]);
                if ($previousEmail !== $user->email) {
                    DB::table('password_reset_tokens')->whereIn('email', [$previousEmail, $user->email])->delete();
                    foreach (array_unique([$previousEmail, $user->email]) as $recipient) {
                        $this->emailDelivery->accountEmailChanged($recipient, $previousEmail, $user->email, (string) Str::uuid(), $user);
                    }
                }

                return $user;
            }

            Validator::make($data, ['confirmed' => ['accepted'], 'current_password' => ['required', 'string']])->validate();
            if (! Hash::check($data['current_password'], $actor->fresh()->password)) {
                throw ValidationException::withMessages(['current_password' => 'A senha informada está incorreta.']);
            }
            if ($user->hasLinkedRecords()) {
                throw ValidationException::withMessages(['account' => 'Esta conta possui registros associados e não pode ser excluída. Desative os vínculos para encerrar o acesso.']);
            }
            foreach ($affiliations as $affiliation) {
                Gate::forUser($actor)->authorize('delete', $affiliation);
            }
            $activeSystemIds = $affiliations->filter(fn (Affiliation $affiliation): bool => $affiliation->type === AffiliationType::SystemAdministrator && $affiliation->deactivated_at === null)->modelKeys();
            if ($activeSystemIds !== [] && ! Affiliation::query()->active()->where('type', AffiliationType::SystemAdministrator)->whereKeyNot($activeSystemIds)->exists()) {
                throw ValidationException::withMessages(['account' => 'Não é possível excluir o último Administrador do Sistema ativo.']);
            }
            $recipients = $affiliations->pluck('email')->push($user->email)->map(fn (string $email): string => mb_strtolower(trim($email)))->unique();
            foreach ($recipients as $recipient) {
                $this->emailDelivery->administrativeChange($recipient, 'Conta excluída', "A conta de {$user->name} e seus vínculos foram excluídos pela administração. O acesso ao sistema foi encerrado.", (string) Str::uuid(), $user);
            }
            foreach ($affiliations as $affiliation) {
                $affiliation->delete();
            }
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->delete();

            return $user;
        });
    }
}
