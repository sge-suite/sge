<?php

namespace App\Http\Requests;

use App\Concerns\AdministrativeAffiliationValidationRules;
use App\Concerns\SupervisorProfessionalDataValidationRules;
use App\Helpers\DigitsHelper;
use App\Models\Affiliation;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class AdministrativeAffiliationRequest extends FormRequest
{
    use AdministrativeAffiliationValidationRules;
    use SupervisorProfessionalDataValidationRules;

    /** @return array<string, array<int, mixed>> */
    abstract public function rules(): array;

    public function authorize(): bool
    {
        $affiliation = $this->route('affiliation');
        abort_unless($affiliation === null || $affiliation instanceof Affiliation, 404);
        if ($affiliation !== null) {
            $user = $this->route('user');
            abort_unless($user instanceof User && $affiliation->user_id === $user->id, 404);
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
        $this->merge($this->normalizeSupervisorProfessionalReference($normalized + $this->all()));
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

    /** @return array{cpf: string, name: string, email: string, type: string, campus_id: int|null, course_id: int|null, registration_number: string|null, job_role: string|null, phone?: string|null, qualifications?: array<array-key, array<string, mixed>>} */
    public function campusCreationData(): array
    {
        $data = $this->safe();

        $result = [
            'cpf' => $data->string('cpf')->toString(),
            'name' => $data->string('name')->toString(),
            'email' => $data->string('email')->toString(),
            'type' => $data->string('type')->toString(),
            'campus_id' => $data->filled('campus_id') ? $data->integer('campus_id') : null,
            'course_id' => $data->filled('course_id') ? $data->integer('course_id') : null,
            'registration_number' => $data->filled('registration_number') ? $data->string('registration_number')->toString() : null,
            'job_role' => $data->filled('job_role') ? $data->string('job_role')->toString() : null,
        ];
        if ($data->has('phone')) {
            $result['phone'] = $data->filled('phone') ? $data->string('phone')->toString() : null;
        }
        if ($data->has('qualifications')) {
            /** @var array<array-key, array<string, mixed>> $qualifications */
            $qualifications = $data->array('qualifications');
            $result['qualifications'] = $qualifications;
        }

        return $result;
    }

    /** @return array{cpf: string, name: string, email: string, type: string, registration_number: string, campus_id: int|null} */
    public function administrativeCreationData(): array
    {
        $data = $this->safe();
        $target = $this->route('user');

        return ['cpf' => $data->string('cpf', $target instanceof User ? $target->cpf : '')->toString(), 'name' => $data->string('name', $target instanceof User ? $target->name : '')->toString(),
            'email' => $data->string('email')->toString(), 'type' => $data->string('type')->toString(),
            'registration_number' => $data->string('registration_number')->toString(),
            'campus_id' => $data->filled('campus_id') ? $data->integer('campus_id') : null];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nome completo', 'cpf' => 'CPF', 'email' => 'e-mail', 'type' => 'tipo de vínculo', 'campus_id' => 'campus', 'registration_number' => 'matrícula', 'course_id' => 'curso', 'job_role' => 'cargo', 'phone' => 'telefone', 'training_course' => 'curso de formação', 'education_level' => 'nível', 'experience_time' => 'tempo de experiência na função', 'current_password' => 'senha atual', 'confirmed' => 'confirmação'] + $this->supervisorQualificationAttributes();
    }
}
