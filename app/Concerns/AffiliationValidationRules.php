<?php

namespace App\Concerns;

use App\Enums\AffiliationType;
use App\Rules\RegistrationNumberBelongsToUser;
use Illuminate\Validation\Rule;

trait AffiliationValidationRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function affiliationRules(): array
    {
        $attributes = $this->getAttributes();
        $type = $attributes['type'] ?? null;
        $campusId = $attributes['campus_id'] ?? null;
        $courseId = $attributes['course_id'] ?? null;
        $requiresActiveCampus = $campusId !== null && (
            ! $this->exists
            || (int) $campusId !== (int) $this->getOriginal('campus_id')
            || ($this->getOriginal('deactivated_at') !== null && ($attributes['deactivated_at'] ?? null) === null)
        );
        $campusExists = $requiresActiveCampus
            ? Rule::exists('campuses', 'id')->whereNull('deactivated_at')->whereNull('deleted_at')
            : Rule::exists('campuses', 'id');
        $requiresActiveCourse = $courseId !== null && (
            ! $this->exists
            || (int) $courseId !== (int) $this->getOriginal('course_id')
            || ($this->getOriginal('deactivated_at') !== null && ($attributes['deactivated_at'] ?? null) === null)
        );
        $courseExists = Rule::exists('courses', 'id')->where('campus_id', $campusId);

        if ($requiresActiveCourse) {
            $courseExists->whereNull('deactivated_at');
        }

        $registrationNumberRules = $type === AffiliationType::Supervisor->value
            ? ['nullable', 'prohibited']
            : ['required', 'string', 'max:255'];

        $requiresRegistrationOwnership = ! $this->exists
            || $this->isDirty(['user_id', 'registration_number'])
            || ($this->getOriginal('deactivated_at') !== null && ($attributes['deactivated_at'] ?? null) === null);

        if ($requiresRegistrationOwnership) {
            $registrationNumberRules[] = new RegistrationNumberBelongsToUser(
                isset($attributes['user_id']) ? (int) $attributes['user_id'] : null,
                $this->exists ? $this->getKey() : null,
            );
        }

        if ($type === AffiliationType::Student->value) {
            $uniqueRegistrationNumber = Rule::unique('affiliations', 'registration_number')
                ->where('type', AffiliationType::Student->value);

            if ($this->exists) {
                $uniqueRegistrationNumber->ignore($this->getKey());
            }

            $registrationNumberRules[] = $uniqueRegistrationNumber;
        }

        return [
            'user_id' => ['bail', 'required', 'integer', Rule::exists('users', 'id')],
            'campus_id' => [
                'bail',
                Rule::requiredIf($type !== AffiliationType::SystemAdministrator->value),
                'nullable',
                'integer',
                $campusExists,
            ],
            'course_id' => [
                'bail',
                Rule::requiredIf($type === AffiliationType::Student->value),
                Rule::prohibitedIf($type !== AffiliationType::Student->value),
                'nullable',
                'integer',
                $courseExists,
            ],
            'type' => ['required', Rule::enum(AffiliationType::class)],
            'registration_number' => $registrationNumberRules,
            'email' => ['required', 'string', 'email', 'max:255'],
            'deactivated_at' => ['nullable', 'date'],
            'last_used_at' => ['nullable', 'date'],
        ];
    }

    protected function nullifyBlankOptionalAffiliationValues(): void
    {
        if (is_string($this->registration_number)) {
            $this->registration_number = trim($this->registration_number);
        }

        foreach (['campus_id', 'course_id', 'registration_number', 'deactivated_at', 'last_used_at'] as $attribute) {
            if (blank($this->getAttributes()[$attribute] ?? null)) {
                $this->{$attribute} = null;
            }
        }
    }
}
