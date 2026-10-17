<?php

namespace App\Policies;

use App\Enums\InternshipRequestCorrectionStatus;
use App\Enums\InternshipRequestStatus;
use App\Models\EmancipationEvidence;
use App\Models\InternshipRequest;
use App\Models\User;

class EmancipationEvidencePolicy
{
    public function view(User $user, EmancipationEvidence $evidence): bool
    {
        $request = $evidence->internshipRequest()->first();

        return $request !== null && ($user->can('view', $request) || $user->can('review', $request));
    }

    public function upload(User $user, InternshipRequest $request): bool
    {
        if (! $user->can('update', $request)) {
            return false;
        }

        return $request->status === InternshipRequestStatus::Draft || $request->corrections()
            ->where('status', InternshipRequestCorrectionStatus::Open)->whereJsonContains('affected_sections', 'legal_capacity')->exists();
    }

    public function review(User $user, EmancipationEvidence $evidence): bool
    {
        return $user->can('review', $evidence->internshipRequest()->firstOrFail());
    }
}
