<?php

namespace App\Actions;

use App\Enums\AffiliationType;
use App\Enums\EvaluationStatus;
use App\Models\SupervisorEvaluation;
use App\Models\User;
use App\Support\StudentInternshipContext;
use Illuminate\Support\Facades\Gate;

class GetSupervisorDashboard
{
    public function __construct(private StudentInternshipContext $context) {}

    /** @return array<string, mixed> */
    public function __invoke(User $actor): array
    {
        Gate::forUser($actor)->authorize('viewAny', SupervisorEvaluation::class);
        $owner = $this->context->selected($actor);
        abort_unless($owner?->type === AffiliationType::Supervisor, 403);
        $query = SupervisorEvaluation::query()->where('supervisor_affiliation_id', $owner->id)
            ->whereHas('internship', fn ($query) => $query->whereNotNull('evaluation_released_at')
                ->where('supervisor_affiliation_id', $owner->id));
        $counts = (clone $query)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $groups = [];
        foreach ([EvaluationStatus::Returned, EvaluationStatus::Draft, EvaluationStatus::Submitted] as $status) {
            $groups[$status->value] = [
                'count' => (int) $counts->get($status->value, 0),
                'evaluations' => (clone $query)->where('status', $status)->with('internship.course.campus')
                    ->oldest('updated_at')->oldest('id')->limit(5)->get(),
            ];
        }

        return [
            'groups' => $groups,
            'total' => (int) $counts->sum(),
            'approved' => (int) $counts->get(EvaluationStatus::Approved->value, 0),
        ];
    }
}
