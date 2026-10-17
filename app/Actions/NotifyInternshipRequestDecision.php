<?php

namespace App\Actions;

use App\Enums\InternshipRequestStatus;
use App\Jobs\DeliverInternshipRequestNotice;
use App\Models\InternshipRequest;
use Illuminate\Support\Str;

class NotifyInternshipRequestDecision
{
    public function handle(InternshipRequest $request): void
    {
        $correction = $request->status === InternshipRequestStatus::PendingCorrection
            ? $request->corrections()->where('status', 'open')->latest('id')->first()
            : null;

        DeliverInternshipRequestNotice::dispatch(
            $request->id,
            (string) Str::uuid(),
            $request->status->label(),
            $correction?->affected_sections ?? [],
            $correction?->section_messages ?? [],
            $correction?->message,
            $correction?->general_message,
        )->afterCommit();
    }
}
