<?php

namespace App\Support;

use App\Models\EmancipationEvidence;
use App\Models\GrantingPartyRegistrationRequest;
use App\Models\InternshipRequest;
use App\Models\InternshipRequestCorrection;
use App\Models\SupervisorRegistrationRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class InternshipRequestActivityScope
{
    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function apply(Builder $query, int $campusId, ?int $requestId = null): Builder
    {
        $requests = InternshipRequest::query()->whereHas('affiliation', fn (Builder $query) => $query->where('campus_id', $campusId))
            ->whereNotNull('terms_accepted_at')->when($requestId !== null, fn (Builder $query) => $query->whereKey($requestId));
        $requestIds = (clone $requests)->select('id');

        return $query->where(function (Builder $query) use ($requests, $requestIds): void {
            $query->where(function (Builder $query) use ($requestIds): void {
                $query->where('subject_type', (new InternshipRequest)->getMorphClass())->whereIn('subject_id', clone $requestIds);
            });

            foreach ([new InternshipRequestCorrection, new EmancipationEvidence] as $subject) {
                $query->orWhere(function (Builder $query) use ($subject, $requestIds): void {
                    $query->where('subject_type', $subject->getMorphClass())
                        ->whereIn('subject_id', $subject->newQuery()->whereIn('internship_request_id', clone $requestIds)->select('id'));
                });
            }

            foreach (['granting_party' => new GrantingPartyRegistrationRequest, 'supervisor' => new SupervisorRegistrationRequest] as $kind => $subject) {
                $query->orWhere(function (Builder $query) use ($kind, $subject, $requests): void {
                    $query->where('subject_type', $subject->getMorphClass())
                        ->whereIn('subject_id', $this->proposalIds($requests, $kind));
                });
            }
        });
    }

    /**
     * Keep proposals discoverable after association or replacement during correction.
     *
     * @param  Builder<InternshipRequest>  $requests
     * @param  'granting_party'|'supervisor'  $kind
     */
    private function proposalIds(Builder $requests, string $kind): QueryBuilder
    {
        $field = $kind.'_registration_request_id';
        $snapshot = $kind.'_snapshot';
        $ids = (clone $requests)->whereNotNull($field)->select($field)->toBase();
        $ids->union((clone $requests)->whereNotNull($snapshot.'->source_registration_request_id')
            ->selectRaw("({$snapshot}->>'source_registration_request_id')::bigint")->toBase());

        foreach (['attributes', 'old'] as $version) {
            $ids->union(DB::query()->from((new Activity)->getTable())
                ->where('subject_type', (new InternshipRequest)->getMorphClass())
                ->whereIn('subject_id', (clone $requests)->select('id'))
                ->whereNotNull('attribute_changes->'.$version.'->'.$field)
                ->selectRaw("(attribute_changes->'{$version}'->>'{$field}')::bigint"));
        }

        return $ids;
    }
}
