<?php

namespace App\Concerns;

use App\Enums\EducationLevel;
use App\Enums\SupervisorQualificationType;
use Illuminate\Validation\Rule;

trait SupervisorProfessionalDataValidationRules
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, mixed>>
     */
    public function supervisorRequestStepRules(array $data): array
    {
        $rules = [
            'supervisor_mode' => ['required', Rule::in(['existing', 'pending'])],
            'supervisor_professional.job_role' => ['required', 'string', 'max:255'],
        ];
        if (($data['supervisor_mode'] ?? null) !== 'pending') {
            $rules['supervisor_affiliation_id'] = ['required', 'integer', 'min:1'];

            return $rules;
        }

        $proposal = $data['supervisor_proposal'] ?? [];
        $choice = $proposal['has_academic_background'] ?? null;
        $background = $choice === null || $choice === '' ? null : (bool) $choice;
        $qualification = $this->supervisorQualificationRules(false, $background);
        unset($qualification['job_role']);
        foreach ($qualification as $field => $fieldRules) {
            $rules['supervisor_proposal.'.$field] = $fieldRules;
        }
        $rules['supervisor_proposal.name'] = ['required', 'string', 'max:255'];
        $rules['supervisor_proposal.cpf'] = ['required', 'cpf'];
        $rules['supervisor_proposal.phone'] = ['required', 'celular_com_ddd'];
        $rules['supervisor_proposal.email'] = ['required', 'email', 'max:254'];

        return $rules;
    }

    /** @return array<string, string> */
    protected function supervisorQualificationAttributes(): array
    {
        return ['qualifications' => 'qualificações', 'qualifications.*.type' => 'tipo de qualificação',
            'qualifications.*.id' => 'qualificação', 'qualifications.*.training_course' => 'curso de formação',
            'qualifications.*.education_level' => 'nível de formação', 'qualifications.*.experience_area' => 'área de experiência',
            'qualifications.*.experience_time' => 'tempo de experiência'];
    }

    /** @return array<string, array<int, mixed>> */
    public function supervisorProfileQualificationRules(): array
    {
        return [
            'qualifications' => ['sometimes', 'array', 'max:25'],
            'qualifications.*' => ['array:id,type,training_course,education_level,experience_area,experience_time'],
            'qualifications.*.id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'qualifications.*.type' => ['required', Rule::enum(SupervisorQualificationType::class)],
            'qualifications.*.training_course' => ['exclude_unless:qualifications.*.type,academic', 'required', 'string', 'max:255'],
            'qualifications.*.education_level' => ['exclude_unless:qualifications.*.type,academic', 'required', Rule::enum(EducationLevel::class)],
            'qualifications.*.experience_area' => ['exclude_unless:qualifications.*.type,professional', 'required', 'string', 'max:255'],
            'qualifications.*.experience_time' => ['exclude_unless:qualifications.*.type,professional', 'required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    protected function supervisorProfileRules(): array
    {
        return ['job_role' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:20', 'celular_com_ddd'], ...$this->supervisorProfileQualificationRules()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeSupervisorProfessionalReference(array $data): array
    {
        foreach (array_keys($this->supervisorProfessionalReferenceRules()) as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $value = trim($data[$field]);
                $data[$field] = $value === '' ? null : $value;
            }
        }

        if (array_key_exists('qualifications', $data)) {
            if ($data['qualifications'] === null || $data['qualifications'] === '') {
                $data['qualifications'] = [];
            } elseif (is_array($data['qualifications'])) {
                $data['qualifications'] = array_map(fn (mixed $row): mixed => is_array($row)
                    ? array_map(fn (mixed $value): mixed => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $row)
                    : $row, $data['qualifications']);
            }
        }

        return $data;
    }

    /** @return array<string, array<int, mixed>> */
    protected function supervisorProfessionalReferenceRules(): array
    {
        return [
            'job_role' => ['nullable', 'string', 'max:255'],
            'training_course' => ['nullable', 'string', 'max:255'],
            'education_level' => ['nullable', Rule::enum(EducationLevel::class)],
            'experience_time' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    protected function supervisorQualificationRules(bool $isDraft, ?bool $hasAcademicBackground): array
    {
        $rules = $this->supervisorProfessionalReferenceRules();
        array_unshift($rules['job_role'], $isDraft ? 'nullable' : 'required');
        foreach (['training_course', 'education_level'] as $field) {
            array_unshift($rules[$field], Rule::requiredIf(! $isDraft && $hasAcademicBackground === true), Rule::prohibitedIf($hasAcademicBackground === false));
        }
        array_unshift($rules['experience_time'], Rule::requiredIf(! $isDraft && $hasAcademicBackground === false), Rule::prohibitedIf($hasAcademicBackground === true));

        return [
            'has_academic_background' => [$isDraft ? 'nullable' : 'required', 'boolean'],
            ...$rules,
        ];
    }
}
