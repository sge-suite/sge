<?php

namespace App\Actions;

use App\Concerns\CampusAffiliationValidationRules;
use App\Enums\AffiliationType;
use App\Helpers\DigitsHelper;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\User;
use App\Support\ActiveAffiliationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

class CreateCampusAffiliation
{
    use CampusAffiliationValidationRules;

    public function __construct(
        private ActiveAffiliationContext $context,
        private CauserResolver $causerResolver,
        private AdministrativeAffiliationTransaction $transaction,
        private RequestEmailDelivery $emailDelivery,
    ) {}

    /** @param array{cpf?: string, name?: string|null, email: string, type: string, campus_id?: int|string|null, course_id?: int|string|null, registration_number?: string|null, job_role?: string|null, qualification?: string|null, training?: string|null, professional_experience?: string|null} $data */
    public function handle(User $actor, array $data, ?User $target = null): Affiliation
    {
        Gate::forUser($actor)->authorize('createOnCampus', User::class);
        if ($target !== null) {
            Gate::forUser($actor)->authorize('create', [Affiliation::class, $target]);
        }
        $selectedId = $this->context->currentFor($actor, app('session.store'))->id;
        $data['cpf'] = $target !== null ? $target->cpf : DigitsHelper::only($data['cpf'] ?? '');
        $data['email'] = mb_strtolower(trim($data['email']));
        if (is_string($data['registration_number'] ?? null)) {
            $data['registration_number'] = trim($data['registration_number']);
        }

        return DB::transaction(function () use ($actor, $selectedId, $data, $target): Affiliation {
            $this->transaction->lockWrites();
            $selected = Affiliation::query()->lockForUpdate()->findOrFail($selectedId);
            $campus = Campus::query()->lockForUpdate()->findOrFail($selected->campus_id);
            $user = $target === null
                ? User::query()->where('cpf', $data['cpf'])->lockForUpdate()->first()
                : User::query()->lockForUpdate()->findOrFail($target->id);
            if (filled($data['course_id'] ?? null)) {
                Course::query()->whereKey($data['course_id'])->lockForUpdate()->first();
            }
            abort_unless($this->context->currentFor($actor, app('session.store'))?->id === $selectedId, 403);
            Gate::forUser($actor)->authorize('createOnCampus', User::class);
            if ($target !== null) {
                Gate::forUser($actor)->authorize('create', [Affiliation::class, $user]);
                $data['cpf'] = $user->cpf;
            }
            $campus->assertWritable();
            Validator::make($data, [...$this->administrativeAccountRules($user), ...$this->campusAffiliationRules($campus->id, $user)])->validate();

            return $this->causerResolver->withCauser($selected, function () use ($data, $campus, $user): Affiliation {
                $isNewAccount = $user === null;
                if ($isNewAccount) {
                    if (User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->exists()) {
                        throw ValidationException::withMessages(['email' => 'Este e-mail de login já está cadastrado.']);
                    }
                    $user = User::query()->create([
                        'name' => trim($data['name'] ?? ''), 'cpf' => $data['cpf'], 'email' => $data['email'], 'password' => Str::password(64),
                    ]);
                }
                $type = AffiliationType::from($data['type']);
                $this->transaction->assertNoActiveDuplicate($user, $type, $campus->id);
                $affiliation = $user->affiliations()->create([
                    'type' => $type, 'campus_id' => $campus->id,
                    'course_id' => $type === AffiliationType::Student ? ($data['course_id'] ?? null) : null,
                    'registration_number' => $type === AffiliationType::Supervisor ? null : ($data['registration_number'] ?? null),
                    'email' => $data['email'],
                ]);
                $professionalData = collect($data)->only(['job_role', 'qualification', 'training', 'professional_experience'])->filter(fn (mixed $value): bool => filled($value))->all();
                if ($type === AffiliationType::Supervisor && $professionalData !== []) {
                    $user->personalData()->updateOrCreate([], $professionalData);
                }
                if ($isNewAccount) {
                    $this->emailDelivery->accountCreated($user->email, (string) Str::uuid(), $affiliation);
                } else {
                    foreach (array_unique(array_map(fn (string $email): string => mb_strtolower(trim($email)), [$user->email, $affiliation->email])) as $recipient) {
                        $this->emailDelivery->affiliationCreated($recipient, $affiliation, (string) Str::uuid());
                    }
                }

                return $affiliation;
            });
        });
    }
}
