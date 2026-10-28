<?php

namespace App\Actions;

use App\Enums\EmancipationEvidenceStatus;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\EmancipationEvidence;
use App\Models\InternshipRequest;
use App\Models\User;
use App\Support\StudentInternshipContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Support\CauserResolver;

class ReviewEmancipationEvidence
{
    public function __construct(private StudentInternshipContext $context, private CauserResolver $causerResolver,
        private ReviewInternshipRequest $reviews) {}

    public function handle(User $actor, EmancipationEvidence $target, bool $approve, ?string $reason = null): EmancipationEvidence
    {
        return DB::transaction(function () use ($actor, $target, $approve, $reason): EmancipationEvidence {
            $selected = $this->context->selected($actor);
            abort_if($selected === null, 403);
            $affiliation = Affiliation::query()->lockForUpdate()->findOrFail($selected->id);
            Campus::query()->lockForUpdate()->findOrFail($affiliation->campus_id)->assertWritable();
            $request = InternshipRequest::query()->lockForUpdate()->findOrFail($target->internship_request_id);
            $evidence = EmancipationEvidence::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('review', $evidence);
            $request->assertUnderReview();
            abort_unless($evidence->status === EmancipationEvidenceStatus::Submitted
                && $request->emancipationEvidences()->latest('id')->value('id') === $evidence->id, 409);
            Validator::make(['reason' => $reason], ['reason' => [$approve ? 'nullable' : 'required', 'string', 'max:5000']])->validate();

            return $this->causerResolver->withCauser($affiliation, function () use ($actor, $evidence, $request, $approve, $reason): EmancipationEvidence {
                $evidence->update(['status' => $approve ? EmancipationEvidenceStatus::Approved : EmancipationEvidenceStatus::Returned,
                    'reviewed_at' => now(), 'return_reason' => $approve ? null : $reason]);
                if ($approve) {
                    $owner = $request->affiliation()->firstOrFail();
                    $profile = $owner->user()->firstOrFail()->personalData()->lockForUpdate()->firstOrFail();
                    $profile->emancipation_verified_at = now();
                    $profile->save();
                } else {
                    $this->reviews->handle($actor, $request, 'return', sections: ['legal_capacity'],
                        sectionMessages: ['legal_capacity' => $reason ?: 'Revise o comprovante de emancipação anexado.']);
                }

                return $evidence;
            });
        });
    }
}
