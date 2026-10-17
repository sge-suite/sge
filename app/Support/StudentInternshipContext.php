<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class StudentInternshipContext
{
    public function __construct(private ActiveAffiliationContext $context) {}

    public function selected(User $actor): ?Affiliation
    {
        if (Auth::id() !== $actor->id) {
            return null;
        }

        $affiliation = $this->context->currentFor($actor, app('session.store'));
        $affiliation?->load(['campus', 'course']);

        return $affiliation;
    }

    public function student(User $actor, bool $writable = false): ?Affiliation
    {
        $affiliation = $this->selected($actor);
        if ($affiliation?->type !== AffiliationType::Student || $affiliation->campus === null
            || $affiliation->course === null || $affiliation->course->campus_id !== $affiliation->campus_id) {
            return null;
        }
        if ($writable && ($affiliation->campus->deactivated_at !== null || $affiliation->course->deactivated_at !== null)) {
            return null;
        }

        return $affiliation;
    }

    public function lock(User $actor, int $expectedAffiliationId): Affiliation
    {
        abort_unless($this->student($actor, writable: true)?->id === $expectedAffiliationId, 403, 'O vínculo selecionado mudou ou não permite alterações. Reabra a solicitação.');
        $affiliation = Affiliation::query()->lockForUpdate()->findOrFail($expectedAffiliationId);
        $campus = Campus::query()->lockForUpdate()->findOrFail($affiliation->campus_id);
        $course = Course::query()->lockForUpdate()->findOrFail($affiliation->course_id);
        abort_unless($affiliation->user_id === $actor->id && $affiliation->deactivated_at === null
            && $affiliation->type === AffiliationType::Student && $course->campus_id === $campus->id
            && $campus->deactivated_at === null && $course->deactivated_at === null, 403);
        $affiliation->setRelation('campus', $campus)->setRelation('course', $course);

        return $affiliation;
    }
}
