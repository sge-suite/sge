<?php

namespace App\Concerns;

use App\Enums\EducationLevel;
use Illuminate\Validation\Rule;

trait SupervisorProfessionalDataValidationRules
{
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
