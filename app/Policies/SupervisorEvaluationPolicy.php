<?php

namespace App\Policies;

use App\Enums\AffiliationType;
use App\Enums\EvaluationStatus;
use App\Enums\InternshipStatus;
use App\Models\Internship;
use App\Models\SupervisorEvaluation;
use App\Models\User;
use App\Support\StudentInternshipContext;
use Illuminate\Database\Eloquent\Builder;

class SupervisorEvaluationPolicy
{
    public function __construct(private StudentInternshipContext $context) {}

    public function viewAny(User $user): bool
    {
        $selected = $this->context->selected($user);

        return $selected?->type === AffiliationType::Supervisor
            || ($selected?->type === AffiliationType::InternshipOffice && $selected->campus !== null);
    }

    public function view(User $user, SupervisorEvaluation $evaluation): bool
    {
        return $this->viewableQuery($user)->whereKey($evaluation->id)->exists();
    }

    /** @return Builder<SupervisorEvaluation> */
    public function viewableQuery(User $user): Builder
    {
        $selected = $this->context->selected($user);
        $query = SupervisorEvaluation::query();

        if ($selected?->type !== AffiliationType::Supervisor
            && ! ($selected?->type === AffiliationType::InternshipOffice && $selected->campus !== null)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('internship', fn (Builder $query): Builder => $query->whereNotNull('evaluation_released_at')
            ->when($selected->type === AffiliationType::Supervisor,
                fn (Builder $query): Builder => $query->where('supervisor_affiliation_id', $selected->id),
                fn (Builder $query): Builder => $query->whereHas('course', fn (Builder $query): Builder => $query->where('campus_id', $selected->campus_id))))
            ->when($selected->type === AffiliationType::Supervisor,
                fn (Builder $query): Builder => $query->where('supervisor_affiliation_id', $selected->id));
    }

    public function release(User $user, Internship $internship): bool
    {
        $selected = $this->context->selected($user);

        return $selected?->type === AffiliationType::InternshipOffice
            && $internship->course()->where('campus_id', $selected->campus_id)->exists()
            && $this->writable($internship)
            && $internship->released_at !== null
            && $internship->supervisorAffiliation?->deactivated_at === null
            && $internship->supervisorAffiliation?->type === AffiliationType::Supervisor
            && ! $internship->supervisorEvaluations()->where('supervisor_affiliation_id', $internship->supervisor_affiliation_id)->exists();
    }

    public function update(User $user, SupervisorEvaluation $evaluation): bool
    {
        return $this->view($user, $evaluation)
            && $this->context->selected($user)->type === AffiliationType::Supervisor
            && $this->writable($evaluation->internship)
            && in_array($evaluation->status, [EvaluationStatus::Draft, EvaluationStatus::Returned], true);
    }

    public function review(User $user, SupervisorEvaluation $evaluation): bool
    {
        return $this->view($user, $evaluation)
            && $this->context->selected($user)->type === AffiliationType::InternshipOffice
            && $this->writable($evaluation->internship)
            && $evaluation->status === EvaluationStatus::Submitted;
    }

    public function cancel(User $user, SupervisorEvaluation $evaluation): bool
    {
        return $this->view($user, $evaluation)
            && $this->context->selected($user)->type === AffiliationType::Supervisor
            && $this->writable($evaluation->internship)
            && in_array($evaluation->status, [EvaluationStatus::Submitted, EvaluationStatus::Returned], true);
    }

    private function writable(Internship $internship): bool
    {
        return in_array($internship->status, [InternshipStatus::Released, InternshipStatus::InProgress, InternshipStatus::Paused], true)
            && $internship->course->deactivated_at === null
            && $internship->course->campus->deactivated_at === null;
    }
}
