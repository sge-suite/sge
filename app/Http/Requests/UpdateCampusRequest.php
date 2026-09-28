<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;
use LaravelLegends\PtBrValidator\Rules\Cnpj;

class UpdateCampusRequest extends CampusFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('campus')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        $campus = $this->route('campus');
        $canEditInstitutionalFields = $this->user()?->can('updateInstitutionalFields', $campus) ?? false;
        $rules = [
            'phone' => ['sometimes', 'required', 'string', 'max:255', $this->phoneRule(), ...$this->uniqueCampusValueWhenChanged('phone', $campus)],
            'legal_representative_name' => ['sometimes', 'required', 'string', 'max:255'],
            'legal_representative_position' => ['sometimes', 'required', 'string', 'max:255'],
            'insurance_company_name' => ['sometimes', 'required', 'string', 'max:255'],
            'insurance_policy_number' => ['sometimes', 'required', 'string', 'max:255'],
        ];

        if ($canEditInstitutionalFields) {
            $rules = [
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'cnpj' => ['sometimes', 'required', 'string', 'size:14', new Cnpj, ...$this->uniqueCampusValueWhenChanged('cnpj', $campus)],
                ...$rules,
                'address' => ['sometimes', 'array:city_id,street,number,neighborhood,zip_code', 'min:1'],
                ...$this->addressRules(partial: true),
            ];
        }

        return $rules;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        $campus = $this->route('campus');
        $allowedKeys = ['phone', 'legal_representative_name', 'legal_representative_position', 'insurance_company_name', 'insurance_policy_number'];

        if ($this->user()?->can('updateInstitutionalFields', $campus)) {
            $allowedKeys = [
                ...$allowedKeys,
                'name', 'cnpj', 'address',
            ];
        }

        return [function (Validator $validator) use ($allowedKeys): void {
            $this->rejectUnexpectedKeys($validator, $allowedKeys);

            $input = $this->all();

            if (array_intersect(array_keys($input), $allowedKeys) === []) {
                $validator->errors()->add('campus', 'Informe ao menos um campo para atualizar.');
            }
        }];
    }
}
