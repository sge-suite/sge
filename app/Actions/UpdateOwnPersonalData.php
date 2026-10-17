<?php

namespace App\Actions;

use App\Concerns\OwnPersonalDataValidationRules;
use App\Helpers\DigitsHelper;
use App\Models\Address;
use App\Models\Affiliation;
use App\Models\User;
use App\Models\UserPersonalData;
use App\Support\ActiveAffiliationContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;
use Spatie\Activitylog\Support\CauserResolver;

class UpdateOwnPersonalData
{
    use OwnPersonalDataValidationRules;

    public function __construct(private ActiveAffiliationContext $context, private CauserResolver $causerResolver, private SyncSupervisorQualifications $qualifications) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, int $expectedAffiliationId, array $data): UserPersonalData
    {
        abort_unless(Auth::id() === $actor->id, 403);
        Gate::forUser($actor)->authorize('viewOwn', UserPersonalData::class);

        return DB::transaction(function () use ($actor, $expectedAffiliationId, $data): UserPersonalData {
            $current = $this->context->currentFor($actor, app('session.store'));
            abort_unless($current !== null && $current->id === $expectedAffiliationId, 403, 'O vínculo selecionado mudou. Reabra os dados pessoais.');
            $affiliation = Affiliation::query()->lockForUpdate()->findOrFail($current->id);
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($affiliation->user_id === $user->id && $affiliation->deactivated_at === null
                && $this->context->currentFor($user, app('session.store'))?->id === $affiliation->id, 403);
            Gate::forUser($user)->authorize('viewOwn', UserPersonalData::class);

            $personalData = $user->personalData()->lockForUpdate()->first() ?? new UserPersonalData(['user_id' => $user->id]);
            Gate::forUser($user)->authorize($personalData->exists ? 'update' : 'create', $personalData->exists ? $personalData : UserPersonalData::class);
            $data = $this->normalizeOwnPersonalData($data);
            $inputValidator = Validator::make($data, $this->ownPersonalDataRules($affiliation->type), attributes: $this->ownPersonalDataAttributes());
            $inputValidator->after(fn (LaravelValidator $validator) => $this->validateOwnPersonalDataFields($validator, $data, $affiliation->type));
            $input = $inputValidator->validate();

            $candidate = $personalData->only(array_diff($this->ownPersonalDataFields($affiliation->type), ['qualifications']));
            foreach (['rg_issue_date', 'birth_date'] as $field) {
                if (array_key_exists($field, $candidate)) {
                    $candidate[$field] = $personalData->{$field}?->toDateString();
                }
            }
            $candidate = array_replace($candidate, $input);
            $address = array_key_exists('address', $input) && $personalData->address_id !== null
                ? Address::query()->lockForUpdate()->findOrFail($personalData->address_id) : null;
            if (is_array($input['address'] ?? null)) {
                $candidate['address'] = array_replace($address?->only(array_keys($this->addressRules())) ?? [], $input['address']);
            }
            $candidate = $this->normalizeOwnPersonalData($candidate);
            $validated = Validator::make($candidate, $this->ownPersonalDataRules($affiliation->type, partial: false), attributes: $this->ownPersonalDataAttributes())->validate();

            return $this->causerResolver->withCauser($affiliation, function () use ($personalData, $validated, $input, $address, $user): UserPersonalData {
                $personalData->fill(collect($validated)->only(array_keys($input))->except(['address', 'qualifications'])->all());
                if (array_key_exists('address', $input)) {
                    $addressData = $validated['address'];
                    if ($addressData === null) {
                        $personalData->address_id = null;
                    } else {
                        $addressData['city_id'] = (int) $addressData['city_id'];
                        $addressData['zip_code'] = DigitsHelper::only($addressData['zip_code']);
                        $replacement = new Address($addressData);
                        if ($address === null || $address->only(array_keys($addressData)) !== $replacement->only(array_keys($addressData))
                            || $address->hasReferencesOutsidePersonalData($personalData)) {
                            if ($personalData->exists) {
                                $replacement->auditOwners = [['type' => $personalData->getMorphClass(), 'id' => $personalData->id, 'name' => '', 'campus_id' => null]];
                            }
                            $replacement->save();
                            $personalData->address_id = $replacement->id;
                        }
                    }
                }
                if ($personalData->exists ? $personalData->isDirty() : collect($personalData->getAttributes())->except('user_id')->contains(fn (mixed $value): bool => filled($value))) {
                    $personalData->save();
                }
                if ($address !== null && $address->id !== $personalData->address_id && ! $address->hasReferences()) {
                    $address->auditOwners = [['type' => $personalData->getMorphClass(), 'id' => $personalData->id, 'name' => '', 'campus_id' => null]];
                    $address->delete();
                }

                if (array_key_exists('qualifications', $input)) {
                    $this->qualifications->handle($user, $validated['qualifications']);
                }

                return $personalData;
            });
        }, attempts: 3);
    }
}
