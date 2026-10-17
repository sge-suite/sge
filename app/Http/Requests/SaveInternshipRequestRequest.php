<?php

namespace App\Http\Requests;

use App\Concerns\InternshipRequestValidationRules;
use App\Models\InternshipRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveInternshipRequestRequest extends FormRequest
{
    use InternshipRequestValidationRules;

    public function authorize(): bool
    {
        $target = $this->route('internshipRequest');

        return $target instanceof InternshipRequest && ($this->user()?->can($this->routeIs('internship-requests.withdraw') ? 'withdraw' : 'update', $target) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->normalizeInternshipInput($this->all()));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['context_affiliation_id' => ['required', 'integer'], 'expected_updated_at' => ['required', 'string'],
            'terms_accepted' => [$this->routeIs('internship-requests.submit') ? 'accepted' : 'nullable', 'boolean']]
            + ($this->routeIs('internship-requests.withdraw') ? ['current_password' => ['required', 'string']] : $this->internshipInputRules());
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->internshipFieldLabels();
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token', '_method']) as $field) {
                $validator->errors()->add($field, 'Este campo não pode ser alterado.');
            }
        }];
    }
}
