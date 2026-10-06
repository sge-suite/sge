<?php

namespace App\Actions;

use App\Concerns\AdministrativeAffiliationValidationRules;
use App\Enums\AffiliationType;
use App\Helpers\DigitsHelper;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateAdministrativeAffiliation
{
    use AdministrativeAffiliationValidationRules;

    public function __construct(private AdministrativeAffiliationTransaction $transaction, private RequestEmailDelivery $emailDelivery) {}

    /** @param array{cpf?: string, name?: string|null, email: string, type: string, campus_id?: int|string|null, registration_number: string} $data */
    public function handle(array $data, ?User $actor = null, ?User $target = null): Affiliation
    {
        $data['email'] = mb_strtolower(trim($data['email']));
        $data['registration_number'] = trim($data['registration_number']);
        $data['cpf'] = DigitsHelper::only($data['cpf'] ?? $target?->cpf);

        return $this->transaction->handle($actor, function (?int $selectedId) use ($data, $actor, $target): Affiliation {
            $user = $target === null
                ? User::query()->where('cpf', $data['cpf'])->lockForUpdate()->first()
                : User::query()->lockForUpdate()->findOrFail($target->id);
            $isNewAccount = $user === null;
            Validator::make($data, [...$this->administrativeAccountRules($user), ...$this->administrativeAffiliationRules()])->validate();

            $type = AffiliationType::from($data['type']);
            $campusId = $type === AffiliationType::CampusAdministrator ? (int) $data['campus_id'] : null;
            if ($campusId !== null) {
                $campus = Campus::query()->lockForUpdate()->find($campusId);
                if ($campus === null || $campus->deactivated_at !== null) {
                    throw ValidationException::withMessages(['campus_id' => 'Selecione um campus ativo.']);
                }
            }

            $this->transaction->authorizeActor($actor, $selectedId);
            if ($actor !== null) {
                Gate::forUser($actor)->authorize('create', Affiliation::class);
            }

            if ($isNewAccount) {
                if (User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->exists()) {
                    throw ValidationException::withMessages(['email' => 'Este e-mail de login já está cadastrado.']);
                }
                $user = User::query()->create([
                    'name' => trim($data['name']), 'cpf' => $data['cpf'], 'email' => $data['email'], 'password' => Str::password(64),
                ]);
            }

            $this->transaction->assertNoActiveDuplicate($user, $type, $campusId);
            $affiliation = $user->affiliations()->create([
                'type' => $type, 'campus_id' => $campusId, 'course_id' => null,
                'email' => $data['email'], 'registration_number' => $data['registration_number'],
            ]);

            if ($isNewAccount) {
                $this->emailDelivery->accountCreated($user->email, (string) Str::uuid());
            } else {
                foreach (array_unique(array_map(fn (string $email): string => mb_strtolower(trim($email)), [$user->email, $affiliation->email])) as $recipient) {
                    $this->emailDelivery->affiliationCreated($recipient, $affiliation, (string) Str::uuid());
                }
            }

            return $affiliation;
        });
    }
}
