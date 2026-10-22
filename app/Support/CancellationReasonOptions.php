<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\InternshipCancellationRequestStatus;
use App\Models\GeneratedDocument;
use App\Models\InternshipCancellationRequest;
use App\Models\User;

class CancellationReasonOptions
{
    public function __construct(private StudentInternshipContext $context) {}

    /** @return array<string, string> */
    public function forUser(User $actor): array
    {
        $selected = $this->context->selected($actor);
        if ($selected?->type !== AffiliationType::InternshipOffice || $selected->campus_id === null) {
            return [];
        }
        $reasons = InternshipCancellationRequest::query()->where('status', InternshipCancellationRequestStatus::Approved)
            ->whereHas('internship.course', fn ($query) => $query->where('campus_id', $selected->campus_id))
            ->latest('id')->limit(100)->get(['reason', 'decision_reason'])
            ->flatMap(fn (InternshipCancellationRequest $request): array => [$request->reason, $request->decision_reason]);
        $documents = GeneratedDocument::query()->where('status', GeneratedDocumentStatus::Cancelled)
            ->whereHas('internship.course', fn ($query) => $query->where('campus_id', $selected->campus_id))
            ->latest('id')->limit(100)->pluck('cancellation_reason');

        return $reasons->merge($documents)->filter(fn ($reason) => is_string($reason) && trim($reason) !== '')
            ->map(fn (string $reason): string => trim($reason))->unique()->sort()->mapWithKeys(fn (string $reason): array => [$reason => $reason])->all();
    }
}
