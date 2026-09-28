<?php

namespace App\Http\Requests;

use App\Models\Campus;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LaravelLegends\PtBrValidator\Rules\Cnpj;

class StoreCampusRequest extends CampusFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Campus::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'cnpj' => ['required', 'string', 'size:14', new Cnpj, Rule::unique(Campus::class, 'cnpj')],
            'phone' => ['required', 'string', 'max:255', $this->phoneRule(), Rule::unique(Campus::class, 'phone')],
            'legal_representative_name' => ['required', 'string', 'max:255'],
            'legal_representative_position' => ['required', 'string', 'max:255'],
            'insurance_company_name' => ['required', 'string', 'max:255'],
            'insurance_policy_number' => ['required', 'string', 'max:255'],
            'address' => ['required', 'array:city_id,street,number,neighborhood,zip_code'],
            ...$this->addressRules(),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->rejectUnexpectedKeys($validator, [
                'name', 'cnpj', 'phone', 'legal_representative_name',
                'legal_representative_position', 'insurance_company_name',
                'insurance_policy_number', 'address',
            ]);
        }];
    }
}
