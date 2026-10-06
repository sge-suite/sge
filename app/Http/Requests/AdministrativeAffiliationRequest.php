<?php

namespace App\Http\Requests;

use App\Concerns\AdministrativeAffiliationValidationRules;
use App\Helpers\DigitsHelper;
use App\Models\Affiliation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class AdministrativeAffiliationRequest extends FormRequest
{
    use AdministrativeAffiliationValidationRules;

    public function authorize(): bool
    {
        $affiliation = $this->route('affiliation');
        if ($affiliation !== null) {
            abort_unless($affiliation->user_id === $this->route('user')->id, 404);
        }

        return $this->user()?->can($this->ability(), $affiliation ?? Affiliation::class) ?? false;
    }

    protected function ability(): string
    {
        return 'create';
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['cpf', 'consulted_cpf'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = DigitsHelper::only($this->input($field));
            }
        }
        foreach (['name', 'email', 'registration_number'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = $field === 'email' ? mb_strtolower(trim($this->input($field))) : trim($this->input($field));
            }
        }
        $this->merge($normalized);
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = [...array_keys($this->rules()), '_token', '_method'];
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'O formulário contém um campo não permitido.');
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nome completo', 'cpf' => 'CPF', 'email' => 'e-mail', 'type' => 'tipo de vínculo', 'campus_id' => 'campus', 'registration_number' => 'registro institucional', 'current_password' => 'senha atual', 'confirmed' => 'confirmação'];
    }
}
