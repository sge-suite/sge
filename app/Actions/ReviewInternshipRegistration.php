<?php

namespace App\Actions;

use App\Enums\AffiliationType;
use App\Enums\RegistrationRequestStatus;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\GrantingParty;
use App\Models\InternshipRequest;
use App\Models\User;
use App\Support\StudentInternshipContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Support\CauserResolver;

class ReviewInternshipRegistration
{
    public function __construct(private StudentInternshipContext $context, private CauserResolver $causerResolver) {}

    public function handle(User $actor, InternshipRequest $target, string $kind, ?int $approvedId, ?string $reason = null): InternshipRequest
    {
        abort_unless(in_array($kind, ['granting_party', 'supervisor'], true), 404);

        return DB::transaction(function () use ($actor, $target, $kind, $approvedId, $reason): InternshipRequest {
            $selected = $this->context->selected($actor);
            abort_if($selected === null, 403);
            $affiliation = Affiliation::query()->lockForUpdate()->findOrFail($selected->id);
            $campus = Campus::query()->lockForUpdate()->findOrFail($affiliation->campus_id);
            $request = InternshipRequest::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('review', $request);
            $campus->assertWritable();
            $request->assertUnderReview();
            $relation = $kind === 'granting_party' ? 'grantingPartyRegistrationRequest' : 'supervisorRegistrationRequest';
            $pending = $request->{$relation}()->lockForUpdate()->firstOrFail();
            abort_unless(in_array($pending->status, [RegistrationRequestStatus::Submitted, RegistrationRequestStatus::UnderReview], true), 409);
            if ($approvedId !== null) {
                $result = $kind === 'granting_party'
                    ? GrantingParty::query()->active()->where('campus_id', $campus->id)->lockForUpdate()->findOrFail($approvedId)
                    : Affiliation::query()->active()->where('campus_id', $campus->id)->where('type', AffiliationType::Supervisor->value)->lockForUpdate()->findOrFail($approvedId);
            } else {
                Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:5000']])->validate();
            }

            return $this->causerResolver->withCauser($affiliation, function () use ($request, $pending, $kind, $approvedId, $reason): InternshipRequest {
                $field = $kind === 'granting_party' ? 'granting_party_id' : 'supervisor_affiliation_id';
                $pending->fill(['status' => $approvedId === null ? RegistrationRequestStatus::Rejected : RegistrationRequestStatus::Approved,
                    'reviewed_at' => now(), 'decision_reason' => $reason, $field => $approvedId]);
                $pending->save();
                if ($approvedId !== null) {
                    $request->{$field} = $approvedId;
                    $request->{$kind.'_registration_request_id'} = null;
                    $request->save();
                }

                return $request;
            });
        });
    }
}
