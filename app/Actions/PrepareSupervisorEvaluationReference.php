<?php

namespace App\Actions;

use App\Models\Internship;
use App\Models\User;
use App\Models\UserPersonalData;
use App\Support\ActiveAffiliationContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class PrepareSupervisorEvaluationReference
{
    public function __construct(private ActiveAffiliationContext $context) {}

    /**
     * Prepare an independent copy for explicit confirmation in the evaluation form.
     *
     * @return array{has_academic_background: bool, job_role: string|null, training_course: string|null, education_level: string|null, experience_time: string|null}
     */
    public function handle(User $actor, int $expectedAffiliationId, int $internshipId, bool $hasAcademicBackground): array
    {
        abort_unless(Auth::id() === $actor->id, 403);
        Gate::forUser($actor)->authorize('updateProfessionalData', UserPersonalData::class);
        $affiliation = $this->context->currentFor($actor, app('session.store'));
        abort_unless($affiliation !== null && $affiliation->id === $expectedAffiliationId, 403);
        $internship = Internship::query()->findOrFail($internshipId);
        abort_unless($internship->supervisor_affiliation_id === $affiliation->id, 403);
        $reference = $actor->personalData()->first();

        return [
            'has_academic_background' => $hasAcademicBackground,
            'job_role' => $reference?->job_role,
            'training_course' => $hasAcademicBackground ? $reference?->training_course : null,
            'education_level' => $hasAcademicBackground ? $reference?->education_level?->value : null,
            'experience_time' => $hasAcademicBackground ? null : $reference?->experience_time,
        ];
    }
}
