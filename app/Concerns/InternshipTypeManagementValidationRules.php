<?php

namespace App\Concerns;

use App\Models\InternshipType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait InternshipTypeManagementValidationRules
{
    use InternshipTypeValidationRules;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, mixed>>
     */
    protected function managementRules(?InternshipType $internshipType, int $campusId, array $data): array
    {
        $requiresActiveCourse = $internshipType === null
            || (int) ($data['course_id'] ?? null) !== $internshipType->course_id;
        $rules = $this->internshipTypeRules($requiresActiveCourse);
        unset($rules['deactivated_at']);
        $courseExists = Rule::exists('courses', 'id')->where('campus_id', $campusId);

        if ($requiresActiveCourse) {
            $courseExists->whereNull('deactivated_at');
        }

        $rules['course_id'] = ['bail', 'required', 'integer', $courseExists];

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowedFields
     */
    protected function validateAllowedFields(Validator $validator, array $data, array $allowedFields): void
    {
        foreach (array_diff(array_keys($data), $allowedFields) as $field) {
            $validator->errors()->add((string) $field, 'O formulário contém um campo não permitido.');
        }
    }
}
