<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\GrantingParty;
use App\Models\InternshipType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class CampusActivityScope
{
    public function __construct(private AddressActivityContext $addresses) {}

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function apply(Builder $query, int $campusId, User $actor): Builder
    {
        return $query->where(function (Builder $query) use ($campusId, $actor): void {
            $query->where(function (Builder $query) use ($campusId): void {
                $query->where('subject_type', (new Campus)->getMorphClass())->where('subject_id', $campusId);
            })->orWhere(function (Builder $query) use ($campusId): void {
                $query->where('subject_type', (new Course)->getMorphClass());
                $this->recordedFieldIn($query, new Course, 'campus_id', [(string) $campusId]);
            })->orWhere(function (Builder $query) use ($campusId): void {
                $query->where('subject_type', (new GrantingParty)->getMorphClass());
                $this->recordedFieldIn($query, new GrantingParty, 'campus_id', [(string) $campusId]);
            })->orWhere(function (Builder $query) use ($campusId): void {
                $query->where('subject_type', (new InternshipType)->getMorphClass())
                    ->where(function (Builder $query) use ($campusId): void {
                        $query->where('properties->campus_id', $campusId)
                            ->orWhere(function (Builder $query) use ($campusId): void {
                                $query->whereNull('properties->campus_id');
                                $courseIds = $this->localCourseIds($campusId);
                                $this->recordedFieldIn($query, new InternshipType, 'course_id', $courseIds);
                            });
                    });
            })->orWhere(function (Builder $query) use ($campusId): void {
                $this->addresses->apply($query, $campusId);
            })->orWhere(function (Builder $query) use ($campusId): void {
                $query->where('subject_type', (new Affiliation)->getMorphClass());
                $this->localAffiliation($query, $campusId);
            })->orWhere(function (Builder $query) use ($campusId, $actor): void {
                $query->where('subject_type', (new User)->getMorphClass())
                    ->whereIn('subject_id', Affiliation::query()->where('campus_id', $campusId)
                        ->where('type', '!=', AffiliationType::SystemAdministrator->value)->select('user_id'))
                    ->where(function (Builder $query) use ($actor): void {
                        $query->where('subject_id', $actor->id)
                            ->orWhereNotIn('subject_id', Affiliation::query()->where('type', AffiliationType::SystemAdministrator)->select('user_id'));
                    });
            });
        });
    }

    /** @return list<string> */
    private function localCourseIds(int $campusId): array
    {
        $foreignEvidence = Activity::query()->where('subject_type', (new Course)->getMorphClass())
            ->where(fn (Builder $query) => $query->where('attribute_changes->attributes->campus_id', '!=', $campusId)
                ->orWhere('attribute_changes->old->campus_id', '!=', $campusId))->select('subject_id');
        $historicalIds = Activity::query()->where('subject_type', (new Course)->getMorphClass())
            ->whereNotIn('subject_id', $foreignEvidence)
            ->where(fn (Builder $query) => $query->where('attribute_changes->attributes->campus_id', $campusId)
                ->orWhere('attribute_changes->old->campus_id', $campusId))->pluck('subject_id');
        $currentIds = Course::query()->where('campus_id', $campusId)->whereNotIn('id', $foreignEvidence)->pluck('id');

        return array_values($currentIds->merge($historicalIds)->unique()->map(fn (int $id): string => (string) $id)->all());
    }

    /** @param Builder<Activity> $query */
    private function localAffiliation(Builder $query, int $campusId): void
    {
        $this->recordedFieldIn($query, new Affiliation, 'campus_id', [(string) $campusId]);
        $types = [...array_diff(AffiliationType::values(), [AffiliationType::SystemAdministrator->value]), AffiliationType::LEGACY_CAMPUS_ADMINISTRATOR];
        $this->recordedFieldIn($query, new Affiliation, 'type', $types);
        $query->where(function (Builder $query): void {
            $query->whereNull('attribute_changes->old->type')
                ->orWhere('attribute_changes->old->type', '!=', AffiliationType::SystemAdministrator->value);
        })->where(function (Builder $query) use ($campusId): void {
            $query->whereNull('attribute_changes->old->campus_id')
                ->orWhere('attribute_changes->old->campus_id', $campusId);
        });
    }

    /**
     * Dirty-only updates omit unchanged scope fields. Recover their value at
     * the event, including deleted subjects, before considering the live row.
     *
     * @param  Builder<Activity>  $query
     * @param  list<string>  $values
     */
    private function recordedFieldIn(Builder $query, Model $subject, string $field, array $values): void
    {
        $table = (new Activity)->getTable();
        $history = DB::query()->from($table.' as campus_scope_history')
            ->selectRaw("CASE WHEN jsonb_exists((campus_scope_history.attribute_changes->'attributes')::jsonb, ?) THEN campus_scope_history.attribute_changes->'attributes'->>? ELSE campus_scope_history.attribute_changes->'old'->>? END AS value", [$field, $field, $field])
            ->whereColumn('campus_scope_history.subject_type', $table.'.subject_type')
            ->whereColumn('campus_scope_history.subject_id', $table.'.subject_id')
            ->whereColumn('campus_scope_history.id', '<=', $table.'.id')
            ->whereRaw("(jsonb_exists((campus_scope_history.attribute_changes->'attributes')::jsonb, ?) OR jsonb_exists((campus_scope_history.attribute_changes->'old')::jsonb, ?))", [$field, $field])
            ->orderByDesc('campus_scope_history.id')->limit(1);

        $query->where(function (Builder $query) use ($history, $subject, $field, $values): void {
            $query->whereExists(function (QueryBuilder $recorded) use ($history, $values): void {
                $recorded->selectRaw('1')->fromSub($history, 'last_campus_scope')->whereIn('last_campus_scope.value', $values);
            })->orWhere(function (Builder $query) use ($history, $subject, $field, $values): void {
                $query->whereNotExists(clone $history)
                    ->whereIn('subject_id', $subject->newQuery()->whereIn($field, $values)->select('id'));
            });
        });
    }
}
