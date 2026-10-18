<?php

namespace App\Support;

use App\Models\Internship;
use App\Models\InternshipCalendarOverride;
use App\Models\InternshipPause;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class InternshipActivityScope
{
    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function apply(Builder $query, int $campusId, ?int $internshipId = null): Builder
    {
        $internshipIds = Internship::query()->whereHas('course', fn (Builder $query) => $query->where('campus_id', $campusId))
            ->when($internshipId !== null, fn (Builder $query) => $query->whereKey($internshipId))->select('id');
        $recordedInternshipIds = (clone $internshipIds)->select([])->selectRaw('CAST(id AS TEXT)');

        return $query->where(function (Builder $query) use ($internshipIds, $recordedInternshipIds): void {
            $query->where(function (Builder $query) use ($internshipIds): void {
                $query->where('subject_type', (new Internship)->getMorphClass())->whereIn('subject_id', clone $internshipIds);
            });

            foreach ([new InternshipPause, new InternshipCalendarOverride] as $subject) {
                $query->orWhere(function (Builder $query) use ($subject, $internshipIds, $recordedInternshipIds): void {
                    $historicalIds = Activity::query()->where('subject_type', $subject->getMorphClass())
                        ->where(function (Builder $query) use ($recordedInternshipIds): void {
                            $query->whereIn('attribute_changes->attributes->internship_id', clone $recordedInternshipIds)
                                ->orWhereIn('attribute_changes->old->internship_id', clone $recordedInternshipIds);
                        })->select('subject_id');
                    $query->where('subject_type', $subject->getMorphClass())->where(function (Builder $query) use ($subject, $internshipIds, $historicalIds): void {
                        $query->whereIn('subject_id', $subject->newQuery()->whereIn('internship_id', clone $internshipIds)->select('id'))
                            ->orWhereIn('subject_id', $historicalIds);
                    });
                });
            }
        });
    }
}
