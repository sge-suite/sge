<?php

namespace App\Support;

use App\Models\Internship;
use App\Models\InternshipRequest;
use App\Models\User;
use App\Policies\InternshipRequestPolicy;
use App\Policies\SupervisorEvaluationPolicy;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class NotificationDestinations
{
    public function __construct(
        private NotificationInbox $inbox,
        private InternshipRequestPolicy $requestPolicy,
        private SupervisorEvaluationPolicy $evaluationPolicy,
    ) {}

    /**
     * @param  Collection<int, DatabaseNotification>  $notifications
     * @return array<string, string|null>
     */
    public function forPage(User $actor, int $affiliationId, Collection $notifications): array
    {
        $this->inbox->affiliation($actor, $affiliationId);
        $evaluationIds = $this->ids($notifications, 'supervisor-evaluations.show', 'supervisor_evaluation_id');
        $requestIds = $this->ids($notifications, 'internship-requests.show', 'internship_request_id');
        $evaluations = $evaluationIds === [] ? [] : $this->evaluationPolicy->viewableQuery($actor)->whereKey($evaluationIds)->pluck('id')->all();
        $requests = $requestIds === [] ? [] : $this->requestPolicy->viewableQuery($actor)->whereKey($requestIds)->pluck('id')->all();
        $canReview = Gate::forUser($actor)->allows('viewReviewList', InternshipRequest::class);
        $canViewInternships = Gate::forUser($actor)->allows('viewAny', Internship::class);
        $destinations = [];

        foreach ($notifications as $notification) {
            $data = Arr::wrap($notification->data);
            $evaluationId = $this->resourceId($data['supervisor_evaluation_id'] ?? null);
            $requestId = $this->resourceId($data['internship_request_id'] ?? null);
            $destinations[$notification->id] = match ($data['route'] ?? null) {
                'supervisor-evaluations.show' => in_array($evaluationId, $evaluations, true)
                    ? route('supervisor-evaluations.show', ['supervisorEvaluation' => $evaluationId]) : null,
                'internship-requests.show' => in_array($requestId, $requests, true)
                    ? route('internship-requests.show', ['internshipRequest' => $requestId]) : null,
                'internship-review.index' => $canReview ? route('internship-review.index') : null,
                'internships.index' => $canViewInternships ? route('internships.index') : null,
                'dashboard' => route('dashboard'),
                default => null,
            };
        }

        return $destinations;
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $notifications
     * @return array<int, int>
     */
    private function ids(Collection $notifications, string $route, string $parameter): array
    {
        return $notifications->filter(fn (DatabaseNotification $notification): bool => data_get($notification->data, 'route') === $route)
            ->map(fn (DatabaseNotification $notification): ?int => $this->resourceId(data_get($notification->data, $parameter)))
            ->filter()->unique()->values()->all();
    }

    private function resourceId(mixed $value): ?int
    {
        if ((! is_int($value) && ! is_string($value)) || ! ctype_digit((string) $value)) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}
