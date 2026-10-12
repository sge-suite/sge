<?php

namespace App\Actions;

use App\Concerns\GrantingPartyValidationRules;
use App\Enums\AffiliationType;
use App\Helpers\BrazilianAddressHelper;
use App\Helpers\DigitsHelper;
use App\Models\Address;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\GrantingParty;
use App\Models\User;
use App\Support\ActiveAffiliationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;
use Spatie\Activitylog\Support\CauserResolver;

class ManageGrantingParty
{
    use GrantingPartyValidationRules;

    public function __construct(private ActiveAffiliationContext $context, private CauserResolver $causerResolver) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, ?GrantingParty $target, string $operation, array $data = []): GrantingParty
    {
        abort_unless(in_array($operation, ['create', 'update', 'deactivate', 'reactivate', 'delete'], true), 404);
        abort_unless(($operation === 'create') === ($target === null), 404);
        Gate::forUser($actor)->authorize($operation, $target ?? GrantingParty::class);

        return DB::transaction(function () use ($actor, $target, $operation, $data): GrantingParty {
            $affiliation = $this->context->currentFor($actor, app('session.store'));
            abort_if($affiliation === null, 403);
            $affiliation = Affiliation::query()->lockForUpdate()->findOrFail($affiliation->id);
            $campus = Campus::query()->lockForUpdate()->findOrFail($affiliation->campus_id);
            $grantingParty = $target === null ? new GrantingParty : GrantingParty::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize($operation, $target === null ? GrantingParty::class : $grantingParty);
            abort_unless($this->context->currentFor($actor, app('session.store'))?->id === $affiliation->id
                && $affiliation->deactivated_at === null && $affiliation->type === AffiliationType::InternshipOffice, 403);
            $campus->assertWritable();

            if (is_array($data['address'] ?? null)) {
                $data['address']['zip_code'] = BrazilianAddressHelper::cepForValidation($data['address']['zip_code'] ?? null);
            }
            $isForm = in_array($operation, ['create', 'update'], true);
            $rules = $isForm
                ? $this->grantingPartyFormRules()
                : (in_array($operation, ['deactivate', 'delete'], true) ? ['current_password' => ['required', 'string']] : []);
            $validator = Validator::make($data, $rules, [], []);
            $validator->after(function (LaravelValidator $validator) use ($data, $rules, $isForm): void {
                $this->validateGrantingPartyFields($validator, $data, array_keys($rules));
                if ($isForm) {
                    $this->validateGrantingPartyInput($validator, $data);
                }
            });
            $validated = $validator->validate();

            if ($operation === 'delete' && $grantingParty->hasLinkedRecords()) {
                throw ValidationException::withMessages(['granting_party' => 'Não é possível excluir uma parte concedente que possui registros vinculados.']);
            }

            if (in_array($operation, ['deactivate', 'delete'], true)
                && ! Hash::check($validated['current_password'], $actor->fresh()->password)) {
                throw ValidationException::withMessages(['current_password' => 'A senha informada está incorreta.']);
            }

            return $this->causerResolver->withCauser($affiliation, function () use ($grantingParty, $operation, $validated, $isForm, $campus): GrantingParty {
                if ($operation === 'delete') {
                    $grantingParty->delete();

                    return $grantingParty;
                }

                if ($isForm) {
                    $addressData = $validated['address'];
                    $addressData['zip_code'] = DigitsHelper::only($addressData['zip_code']);
                    $addressData['city_id'] = (int) $addressData['city_id'];
                    unset($validated['address']);
                    $address = $grantingParty->exists ? Address::query()->lockForUpdate()->findOrFail($grantingParty->address_id) : null;
                    $replacement = new Address($addressData);
                    if ($address === null || $address->only(array_keys($addressData)) !== $replacement->only(array_keys($addressData))) {
                        $replacement->save();
                        $grantingParty->address_id = $replacement->id;
                    }
                    if (! $grantingParty->exists) {
                        $grantingParty->campus_id = $campus->id;
                    }
                    $grantingParty->fill($validated);
                } else {
                    $grantingParty->deactivated_at = $operation === 'deactivate' ? now() : null;
                }
                $grantingParty->save();

                return $grantingParty;
            });
        });
    }
}
