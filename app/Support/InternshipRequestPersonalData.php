<?php

namespace App\Support;

use App\Actions\UpdateOwnPersonalData;
use App\Concerns\OwnPersonalDataValidationRules;
use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\InternshipRequest;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InternshipRequestPersonalData
{
    use OwnPersonalDataValidationRules;

    /** @return array<string, mixed> */
    public function values(InternshipRequest $request, Affiliation $owner): array
    {
        $profile = $owner->user()->firstOrFail()->personalData()->with('address')->first();
        $fields = $this->ownPersonalDataFields(AffiliationType::Student);
        $data = array_fill_keys($fields, null);
        foreach ($fields as $field) {
            $data[$field] = in_array($field, ['birth_date', 'rg_issue_date'], true)
                ? $profile?->{$field}?->toDateString() : $profile?->{$field};
        }
        $data['address'] = $profile?->address?->only(array_keys($this->addressRules())) ?? array_fill_keys(array_keys($this->addressRules()), null);
        if ($request->terms_accepted_at !== null) {
            $data = array_replace($data, array_intersect_key($request->student_snapshot ?? [], array_flip($fields)));
            $data['address'] = $request->studentAddress?->only(array_keys($this->addressRules())) ?? $data['address'];
        }

        return array_replace_recursive($data, $request->student_snapshot['personal_data_draft'] ?? []);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(bool $complete = false): array
    {
        $rules = ['personal_data' => [$complete ? 'required' : 'sometimes', 'array:rg,rg_issuer,rg_issue_date,birth_date,phone,address']];
        foreach ($this->ownPersonalDataRules(AffiliationType::Student) as $field => $fieldRules) {
            $fieldRules = array_values(array_filter($fieldRules, fn (mixed $rule): bool => ! in_array($rule, ['sometimes', 'nullable'], true)));
            $rules['personal_data.'.$field] = [$complete ? 'required' : 'sometimes', ...($complete ? [] : ['nullable']), ...$fieldRules];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        $labels = [];
        foreach ($this->ownPersonalDataAttributes() as $field => $label) {
            $labels['personal_data.'.$field] = $label;
        }

        return $labels;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validateComplete(array $data, Affiliation $owner): array
    {
        $validated = Validator::make(['personal_data' => $this->normalizeOwnPersonalData($data)], $this->rules(true), attributes: $this->labels())->validate();
        $user = $owner->user()->firstOrFail();
        Validator::make(['student_name' => $user->name, 'student_cpf' => $user->cpf, 'student_email' => $owner->email, 'student_registration' => $owner->registration_number], [
            'student_name' => ['required'], 'student_cpf' => ['required', 'cpf'], 'student_email' => ['required', 'email'], 'student_registration' => ['required'],
        ], attributes: ['student_name' => 'nome', 'student_cpf' => 'CPF', 'student_email' => 'e-mail do vínculo', 'student_registration' => 'matrícula'])->validate();

        return $validated['personal_data'];
    }

    public function apply(InternshipRequest $request, Affiliation $owner, User $actor): void
    {
        $data = $this->validateComplete($this->values($request, $owner), $owner);
        app(UpdateOwnPersonalData::class)->handle($actor, $owner->id, $data);
    }

    /** @param array<string, mixed> $data */
    public function saveDraft(array $data, Affiliation $owner, User $actor): void
    {
        $data = $this->normalizeOwnPersonalData($data);
        if (is_array($data['address'] ?? null)
            && collect(array_keys($this->addressRules()))->contains(fn (string $field): bool => blank($data['address'][$field] ?? null))) {
            unset($data['address']);
        }

        $identityFields = ['rg', 'rg_issuer', 'rg_issue_date'];
        $profile = $owner->user()->firstOrFail()->personalData()->first();
        $identity = array_replace($profile?->only($identityFields) ?? array_fill_keys($identityFields, null), array_intersect_key($data, array_flip($identityFields)));
        if (collect($identity)->contains(fn (mixed $value): bool => filled($value))
            && collect($identity)->contains(fn (mixed $value): bool => blank($value))) {
            $data = array_diff_key($data, array_flip($identityFields));
        }

        try {
            app(UpdateOwnPersonalData::class)->handle($actor, $owner->id, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ['personal_data.'.$field => $messages])->all());
        }
    }
}
