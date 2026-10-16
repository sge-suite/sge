<?php

namespace App\Concerns;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\User;
use App\Rules\RegistrationNumberBelongsToUser;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;

trait CampusAffiliationValidationRules
{
    use AdministrativeAffiliationValidationRules;
    use SupervisorProfessionalDataValidationRules;

    /** @return array<string, array<int, mixed>> */
    public function campusAffiliationEditableRules(Affiliation $affiliation, User $user): array
    {
        $isSupervisor = $affiliation->type === AffiliationType::Supervisor;
        $registrationRules = ['bail', Rule::requiredIf(! $isSupervisor), Rule::prohibitedIf($isSupervisor), 'nullable', 'string', 'max:255', new RegistrationNumberBelongsToUser($user->id, $affiliation->id)];
        if ($affiliation->type === AffiliationType::Student) {
            $registrationRules[] = Rule::unique('affiliations', 'registration_number')->where('type', AffiliationType::Student->value)->ignore($affiliation);
        }

        return [
            'email' => $this->administrativeAffiliationEditableRules($user)['email'],
            'registration_number' => $registrationRules,
            ...array_map(fn (array $rules): array => [Rule::prohibitedIf(! $isSupervisor), ...$rules], $this->supervisorProfessionalReferenceRules()),
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public function campusAffiliationRules(int $campusId, ?User $user = null): array
    {
        $registrationRules = ['bail', 'required_unless:type,supervisor', 'prohibited_if:type,supervisor', 'nullable', 'string', 'max:255', new RegistrationNumberBelongsToUser($user?->id)];
        $registrationRules[] = Rule::when(fn (Fluent $input): bool => $input->get('type') === AffiliationType::Student->value, [
            Rule::unique('affiliations', 'registration_number')->where('type', AffiliationType::Student->value),
        ]);

        return [
            'type' => ['required', Rule::enum(AffiliationType::class)->except(AffiliationType::SystemAdministrator)],
            'campus_id' => ['bail', 'nullable', 'integer', Rule::in([$campusId]), Rule::exists(Campus::class, 'id')->whereNull('deactivated_at')],
            'course_id' => ['bail', 'required_if:type,student', 'prohibited_unless:type,student', 'nullable', 'integer', Rule::exists(Course::class, 'id')->where('campus_id', $campusId)->whereNull('deactivated_at')],
            'email' => $this->administrativeAffiliationEditableRules($user)['email'],
            'registration_number' => $registrationRules,
            ...array_map(fn (array $rules): array => ['prohibited_unless:type,supervisor', ...$rules], $this->supervisorProfessionalReferenceRules()),
        ];
    }
}
