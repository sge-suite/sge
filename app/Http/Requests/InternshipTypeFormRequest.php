<?php

namespace App\Http\Requests;

use App\Concerns\InternshipTypeManagementValidationRules;
use App\Models\InternshipType;
use App\Support\ActiveAffiliationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class InternshipTypeFormRequest extends FormRequest
{
    use InternshipTypeManagementValidationRules;

    protected function prepareForValidation(): void
    {
        foreach (['very_good_value', 'good_value', 'satisfactory_value', 'unsatisfactory_value'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $this->merge([$field => str_replace(',', '.', $value)]);
            }
        }
    }

    /** @return array<string, array<int, mixed>> */
    abstract public function rules(): array;

    /** @return array<string, array<int, mixed>> */
    protected function formRules(): array
    {
        $internshipType = $this->route('internshipType');
        $affiliation = app(ActiveAffiliationContext::class)->currentFor($this->user(), app('session.store'));

        return $this->managementRules($internshipType instanceof InternshipType ? $internshipType : null, $affiliation->campus_id, $this->all());
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $rules = $this->rules();
            $this->validateAllowedFields($validator, $this->all(), [...array_keys($rules), '_token', '_method']);
            if (array_key_exists('supervisor_evaluation_weight', $rules)) {
                $this->validateInternshipTypeValues($validator, $this->all());
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->internshipTypeValidationMessages() + ['current_password.current_password' => 'A senha informada está incorreta.'];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->internshipTypeValidationAttributes() + ['current_password' => 'senha atual'];
    }
}
