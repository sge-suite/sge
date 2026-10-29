<?php

namespace App\Policies;

use App\Enums\AffiliationType;
use App\Enums\InternshipRequestStatus;
use App\Models\InternshipRequest;
use App\Models\User;
use App\Support\StudentInternshipContext;
use Illuminate\Database\Eloquent\Builder;

class InternshipRequestPolicy
{
    public function __construct(private StudentInternshipContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->student($user) !== null;
    }

    public function view(User $user, InternshipRequest $request): bool
    {
        return $this->viewableQuery($user)->whereKey($request->id)->exists();
    }

    /** @return Builder<InternshipRequest> */
    public function viewableQuery(User $user): Builder
    {
        $affiliation = $this->context->student($user);

        return InternshipRequest::query()->where('affiliation_id', $affiliation?->id)->when($affiliation === null,
            fn (Builder $query): Builder => $query->whereRaw('1 = 0'));
    }

    public function create(User $user): bool
    {
        return $this->context->student($user, writable: true) !== null;
    }

    public function update(User $user, InternshipRequest $request): bool
    {
        return $this->view($user, $request) && $this->create($user)
            && in_array($request->status, [InternshipRequestStatus::Draft, InternshipRequestStatus::PendingCorrection], true);
    }

    public function submit(User $user, InternshipRequest $request): bool
    {
        return $this->update($user, $request);
    }

    public function withdraw(User $user, InternshipRequest $request): bool
    {
        return $this->view($user, $request) && $this->create($user) && $request->internship_id === null
            && in_array($request->status, [InternshipRequestStatus::Draft, InternshipRequestStatus::Submitted,
                InternshipRequestStatus::UnderReview, InternshipRequestStatus::PendingCorrection], true);
    }

    public function review(User $user, InternshipRequest $request): bool
    {
        $affiliation = $this->context->selected($user);
        $owner = $request->affiliation()->first();

        return $affiliation?->type === AffiliationType::InternshipOffice
            && $affiliation->campus_id !== null && $affiliation->campus_id === $owner?->campus_id
            && ! in_array($request->status, [InternshipRequestStatus::Draft, InternshipRequestStatus::Withdrawn], true);
    }

    public function viewReviewList(User $user): bool
    {
        $affiliation = $this->context->selected($user);

        return $affiliation?->type === AffiliationType::InternshipOffice && $affiliation->campus !== null;
    }
}
