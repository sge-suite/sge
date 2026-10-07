<?php

namespace App\Support;

use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\Activitylog\Models\Activity;

class ActivityHistory
{
    public function __construct(private AdministrativeActivityPresenter $presenter) {}

    /**
     * @param  Builder<Activity>  $query
     * @return LengthAwarePaginator<int, array{id: int, actor: string, subject: string, event: string, occurred_at: string}>
     */
    public function forSubject(Model $subject, Builder $query): LengthAwarePaginator
    {
        $query->where(function (Builder $query) use ($subject): void {
            if ($subject instanceof User) {
                $query->where(function (Builder $query) use ($subject): void {
                    $query->where('subject_type', $subject->getMorphClass())
                        ->where('subject_id', $subject->getKey());
                })->orWhere(function (Builder $query) use ($subject): void {
                    $query->where('subject_type', (new Affiliation)->getMorphClass())
                        ->where(function (Builder $query) use ($subject): void {
                            $query->whereIn('subject_id', $subject->affiliations()->select('id'))
                                ->orWhereIn('subject_id', $this->historicalAffiliationIdsFor($subject));
                        });
                });

                return;
            }

            $query->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->getKey());
        });

        $activities = $query->with([
            'subject' => function ($relation): void {
                /** @var MorphTo<Model, Activity> $relation */
                $relation->constrain([
                    Campus::class => function ($query): void {
                        /** @var Builder<Campus> $query */
                        $query->withTrashed();
                    },
                ]);
                $relation->morphWith([Affiliation::class => ['user']]);
            },
            'causer' => function ($relation): void {
                /** @var MorphTo<Model, Activity> $relation */
                $relation->morphWith([Affiliation::class => ['user']]);
            },
        ])->orderByDesc('created_at')->orderByDesc('id')->paginate(5, pageName: 'auditHistoryPage');

        return $activities->through(fn (Activity $activity): array => [
            'id' => $activity->id,
            ...$this->presenter->summary($activity),
        ]);
    }

    /** @return Builder<Activity> */
    private function historicalAffiliationIdsFor(User $user): Builder
    {
        return Activity::query()
            ->where('subject_type', (new Affiliation)->getMorphClass())
            ->where(function (Builder $query) use ($user): void {
                $query->whereRaw("attribute_changes->'attributes'->>'user_id' = ?", [(string) $user->getKey()])
                    ->orWhereRaw("attribute_changes->'old'->>'user_id' = ?", [(string) $user->getKey()]);
            })
            ->select('subject_id')
            ->distinct();
    }
}
