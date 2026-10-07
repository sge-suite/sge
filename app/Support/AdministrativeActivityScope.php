<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class AdministrativeActivityScope
{
    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function apply(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('subject_type', (new Campus)->getMorphClass())
                ->orWhere(function (Builder $query): void {
                    $query->where('subject_type', (new Affiliation)->getMorphClass())
                        ->where(function (Builder $query): void {
                            $this->recordedAdministrativeType($query);
                            $query->orWhere(function (Builder $query): void {
                                $query->whereNull('attribute_changes->attributes->type')
                                    ->whereNull('attribute_changes->old->type');
                                $this->administrativeTypeAtEvent($query);
                            });
                        });
                })
                ->orWhere(function (Builder $query): void {
                    $query->where('subject_type', (new User)->getMorphClass())
                        ->where(function (Builder $query): void {
                            $query->whereIn('subject_id', Affiliation::query()->administrative()->select('user_id'))
                                ->orWhere(function (Builder $query): void {
                                    $query->whereNotIn('subject_id', User::query()->select('id'));
                                    $this->hasDeletedAdministrativeAccountEvidence($query);
                                });
                        });
                });
        });
    }

    /** @param Builder<Activity> $query */
    private function recordedAdministrativeType(Builder $query): void
    {
        $types = [AffiliationType::SystemAdministrator->value, AffiliationType::CampusAdministrator->value];

        $query->whereIn('attribute_changes->attributes->type', $types)
            ->orWhereIn('attribute_changes->old->type', $types);
    }

    /**
     * Dirty-only updates omit the type. Use the last recorded type at that
     * event, falling back to the live affiliation only when no type was logged.
     *
     * @param  Builder<Activity>  $query
     */
    private function administrativeTypeAtEvent(Builder $query): void
    {
        $table = (new Activity)->getTable();
        $recordedType = "COALESCE(affiliation_type_history.attribute_changes->'attributes'->>'type', affiliation_type_history.attribute_changes->'old'->>'type')";
        $history = DB::query()->from($table.' as affiliation_type_history')
            ->selectRaw($recordedType.' AS type')
            ->whereColumn('affiliation_type_history.subject_type', $table.'.subject_type')
            ->whereColumn('affiliation_type_history.subject_id', $table.'.subject_id')
            ->whereColumn('affiliation_type_history.id', '<=', $table.'.id')
            ->whereRaw($recordedType.' IS NOT NULL')
            ->orderByDesc('affiliation_type_history.id')->limit(1);
        $types = [AffiliationType::SystemAdministrator->value, AffiliationType::CampusAdministrator->value];

        $query->where(function (Builder $query) use ($history, $types): void {
            $query->whereExists(function (QueryBuilder $recorded) use ($history, $types): void {
                $recorded->selectRaw('1')->fromSub($history, 'last_affiliation_type')
                    ->whereIn('last_affiliation_type.type', $types);
            })
                ->orWhere(function (Builder $query) use ($history): void {
                    $query->whereNotExists(clone $history)
                        ->whereIn('subject_id', Affiliation::query()->administrative()->select('id'));
                });
        });
    }

    /**
     * Deleted accounts have no affiliations left; only a recorded administrative
     * type and user ID in the same historical snapshot establish their scope.
     *
     * @param  Builder<Activity>  $query
     */
    private function hasDeletedAdministrativeAccountEvidence(Builder $query): void
    {
        /** @var literal-string $table */
        $table = (new Activity)->getTable();
        $types = [AffiliationType::SystemAdministrator->value, AffiliationType::CampusAdministrator->value];

        $query->whereExists(function (QueryBuilder $history) use ($table, $types): void {
            $history->selectRaw('1')->from($table.' as affiliation_history')
                ->where('affiliation_history.subject_type', (new Affiliation)->getMorphClass())
                ->where(function (QueryBuilder $snapshots) use ($table, $types): void {
                    foreach (['attributes', 'old'] as $snapshot) {
                        $snapshots->orWhere(function (QueryBuilder $recorded) use ($table, $types, $snapshot): void {
                            $recorded->whereIn('affiliation_history.attribute_changes->'.$snapshot.'->type', $types)
                                ->whereRaw("affiliation_history.attribute_changes->?->>'user_id' = \"{$table}\".subject_id::text", [$snapshot]);
                        });
                    }
                });
        });
    }
}
