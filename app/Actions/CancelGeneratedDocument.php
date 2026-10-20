<?php

namespace App\Actions;

use App\Enums\GeneratedDocumentStatus;
use App\Models\GeneratedDocument;
use App\Models\Internship;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Support\CauserResolver;

class CancelGeneratedDocument
{
    public function __construct(private ManageDocumentTemplate $templates, private CauserResolver $causers) {}

    public function handle(User $actor, int $expectedAffiliationId, GeneratedDocument $target, string $reason): void
    {
        $reason = trim($reason);
        Validator::make(['cancellation_reason' => $reason], ['cancellation_reason' => ['required', 'string', 'max:5000']])->validate();
        DB::transaction(function () use ($actor, $expectedAffiliationId, $target, $reason): void {
            $affiliation = $this->templates->lockOffice($actor, $expectedAffiliationId);
            Internship::query()->lockForUpdate()->findOrFail($target->internship_id);
            $document = GeneratedDocument::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('cancel', $document);
            $this->causers->withCauser($affiliation, fn (): bool => $document->update(['status' => GeneratedDocumentStatus::Cancelled,
                'cancelled_at' => now(), 'cancellation_reason' => $reason]));
        });
    }
}
