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

    /** @return array{cpf: string, name: string, email: string, type: string, campus_id: int|null, course_id: int|null, registration_number: string|null, job_role: string|null, qualification: string|null, training: string|null, professional_experience: string|null} */
    public function campusCreationData(): array
    {
        $data = $this->safe();

        return [
            'cpf' => $data->string('cpf')->toString(),
            'name' => $data->string('name')->toString(),
            'email' => $data->string('email')->toString(),
            'type' => $data->string('type')->toString(),
            'campus_id' => $data->filled('campus_id') ? $data->integer('campus_id') : null,
            'course_id' => $data->filled('course_id') ? $data->integer('course_id') : null,
            'registration_number' => $data->filled('registration_number') ? $data->string('registration_number')->toString() : null,
            'job_role' => $data->filled('job_role') ? $data->string('job_role')->toString() : null,
            'qualification' => $data->filled('qualification') ? $data->string('qualification')->toString() : null,
            'training' => $data->filled('training') ? $data->string('training')->toString() : null,
            'professional_experience' => $data->filled('professional_experience') ? $data->string('professional_experience')->toString() : null,
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nome completo', 'cpf' => 'CPF', 'email' => 'e-mail', 'type' => 'tipo de vínculo', 'campus_id' => 'campus', 'registration_number' => 'matrícula', 'course_id' => 'curso', 'job_role' => 'cargo', 'qualification' => 'qualificação', 'training' => 'formação', 'professional_experience' => 'experiência profissional', 'current_password' => 'senha atual', 'confirmed' => 'confirmação'];
    }
}
