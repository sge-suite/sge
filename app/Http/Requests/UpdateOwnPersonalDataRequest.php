<?php

namespace App\Http\Requests;

use App\Concerns\OwnPersonalDataValidationRules;
use App\Models\UserPersonalData;
use App\Support\ActiveAffiliationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateOwnPersonalDataRequest extends FormRequest
{
    use OwnPersonalDataValidationRules;

    public function authorize(): bool
    {
        return $this->user()?->can('viewOwn', UserPersonalData::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->normalizeOwnPersonalData($this->all()));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $affiliation = app(ActiveAffiliationContext::class)->currentFor($this->user(), app('session.store'));

        return ['context_affiliation_id' => ['required', 'integer', 'in:'.$affiliation->id]] + $this->ownPersonalDataRules($affiliation->type);
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $affiliation = app(ActiveAffiliationContext::class)->currentFor($this->user(), app('session.store'));
            $this->validateOwnPersonalDataFields($validator, $this->all(), $affiliation->type, ['context_affiliation_id', '_token', '_method']);
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->ownPersonalDataAttributes();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['context_affiliation_id.in' => 'O vínculo selecionado mudou. Reabra os dados pessoais antes de salvar.'];
    }
}
