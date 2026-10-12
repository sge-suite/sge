<?php

namespace App\Actions;

use App\Concerns\CampusAffiliationValidationRules;
use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateAdministrativeAffiliation
{
    use CampusAffiliationValidationRules;

    public function __construct(private AdministrativeAffiliationTransaction $transaction, private RequestEmailDelivery $emailDelivery) {}

    /** @param array{email?: string, registration_number?: string|null, job_role?: string|null, qualification?: string|null, training?: string|null, professional_experience?: string|null, current_password?: string, confirmed?: mixed} $data */
    public function handle(User $actor, User $target, Affiliation $affiliation, string $operation, array $data): Affiliation
    {
        abort_unless(in_array($operation, ['update', 'deactivate', 'delete', 'reactivate'], true), 404);
        $allowCampusActor = in_array($operation, ['update', 'deactivate', 'delete'], true)
            || ($operation === 'reactivate' && $affiliation->user_id === $actor->id);

        return $this->transaction->handle($actor, function (?int $selectedId) use ($actor, $target, $affiliation, $operation, $data, $allowCampusActor): Affiliation {
            $user = User::query()->lockForUpdate()->findOrFail($target->id);
            $affiliation = $user->affiliations()->lockForUpdate()->findOrFail($affiliation->id);
            if ($affiliation->campus_id !== null) {
                $campus = Campus::query()->lockForUpdate()->find($affiliation->campus_id);
                abort_if($campus === null || $campus->deactivated_at !== null, 403);
            }
            $this->transaction->authorizeActor($actor, $selectedId, $allowCampusActor);
            Gate::forUser($actor)->authorize($operation, $affiliation);

            if ($operation === 'update') {
                $data['email'] = mb_strtolower(trim($data['email'] ?? ''));
                if (is_string($data['registration_number'] ?? null)) {
                    $data['registration_number'] = trim($data['registration_number']);
                }
                $rules = $actor->can('createOnCampus', User::class)
                    ? $this->campusAffiliationEditableRules($affiliation, $user)
                    : $this->administrativeAffiliationEditableRules($user);
                $data = Validator::make($data, $rules)->validate();
                $affiliation->update(collect($data)->only(['email', 'registration_number'])->all());
                $professionalData = collect($data)->only(['job_role', 'qualification', 'training', 'professional_experience']);
                if ($affiliation->type === AffiliationType::Supervisor && $professionalData->isNotEmpty()
                    && ($professionalData->contains(fn (mixed $value): bool => filled($value)) || $user->personalData()->exists())) {
                    $user->personalData()->updateOrCreate([], $professionalData->all());
                }
            } elseif (in_array($operation, ['deactivate', 'delete'], true)) {
                Validator::make($data, ['confirmed' => ['accepted'], 'current_password' => ['required', 'string']])->validate();
                if (! Hash::check($data['current_password'], $actor->fresh()->password)) {
                    throw ValidationException::withMessages(['current_password' => 'A senha informada está incorreta.']);
                }
                if ($affiliation->type === AffiliationType::SystemAdministrator && $affiliation->deactivated_at === null && Affiliation::query()->active()->where('type', AffiliationType::SystemAdministrator)->count() <= 1) {
                    throw ValidationException::withMessages(['affiliation' => 'Não é possível remover o último Administrador do Sistema ativo.']);
                }
                if ($operation === 'delete' && $affiliation->hasLinkedRecords()) {
                    throw ValidationException::withMessages(['affiliation' => 'Este vínculo possui registros associados e não pode ser excluído. Desative-o para encerrar o acesso.']);
                }
                $subject = $operation === 'delete' ? 'Vínculo excluído' : 'Vínculo desativado';
                $registration = $affiliation->registration_number === null ? '' : ", matrícula {$affiliation->registration_number},";
                $body = "O vínculo {$affiliation->type->label()} de {$user->name}{$registration} foi ".($operation === 'delete' ? 'excluído.' : 'desativado.').' O acesso por este vínculo foi encerrado.';
                foreach (array_unique(array_map(fn (string $email): string => mb_strtolower(trim($email)), [$user->email, $affiliation->email])) as $recipient) {
                    $this->emailDelivery->administrativeChange($recipient, $subject, $body, (string) Str::uuid(), $affiliation);
                }
                if ($operation === 'delete') {
                    $affiliation->delete();
                } else {
                    $affiliation->update(['deactivated_at' => now()]);
                }
            } elseif ($operation === 'reactivate') {
                Validator::make($data, ['confirmed' => ['accepted']])->validate();
                $this->transaction->assertNoActiveDuplicate($user, $affiliation->type, $affiliation->campus_id, $affiliation->id);
                $affiliation->update(['deactivated_at' => null]);
            }

            return $affiliation;
        }, $allowCampusActor);
    }
}
