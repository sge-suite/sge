<?php

namespace App\Support;

use App\Models\Address;
use App\Models\Campus;
use App\Models\GrantingParty;
use App\Models\Internship;
use App\Models\UserPersonalData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class AddressActivityContext
{
    /** @var Collection<int, Activity>|null */
    private ?Collection $preparedOwnerEvents = null;

    /** @param Collection<int, Activity> $activities */
    public function prepare(Collection $activities): void
    {
        $ids = $activities->filter(fn (Activity $activity): bool => $activity->subject_type === (new Address)->getMorphClass()
            && $activity->properties?->get('address_context') === null)->pluck('subject_id')->all();
        $this->preparedOwnerEvents = $ids === [] ? collect() : Activity::query()
            ->whereIn('subject_type', [(new Campus)->getMorphClass(), (new GrantingParty)->getMorphClass()])
            ->where(fn (Builder $query) => $query->whereIn('attribute_changes->attributes->address_id', $ids)
                ->orWhereIn('attribute_changes->old->address_id', $ids))->orderBy('id')->get();
    }

    /** @return list<array{type: string, id: int, name: string, campus_id: int|null}> */
    public function owners(Address $address): array
    {
        $owners = [];
        foreach ([Campus::class, GrantingParty::class] as $model) {
            foreach ($model::query()->where('address_id', $address->id)->get(['id', 'name', ...($model === GrantingParty::class ? ['campus_id'] : [])]) as $owner) {
                $owners[] = ['type' => $owner->getMorphClass(), 'id' => $owner->id, 'name' => $owner->name,
                    'campus_id' => $model === Campus::class ? $owner->id : $owner->campus_id];
            }
        }
        foreach (UserPersonalData::query()->where('address_id', $address->id)->get(['id']) as $owner) {
            $owners[] = ['type' => $owner->getMorphClass(), 'id' => $owner->id, 'name' => '', 'campus_id' => null];
        }
        foreach (Internship::query()->where(fn (Builder $query) => $query->where('student_address_id', $address->id)->orWhere('workplace_address_id', $address->id))->get(['id']) as $owner) {
            $owners[] = ['type' => $owner->getMorphClass(), 'id' => $owner->id, 'name' => '', 'campus_id' => null];
        }

        return $owners;
    }

    public function record(Activity $activity, Address $address): void
    {
        $owners = $address->auditOwners ?? $this->owners($address);
        if ($owners === []) {
            return;
        }
        $catalogTypes = [(new Campus)->getMorphClass(), (new GrantingParty)->getMorphClass()];
        $campusIds = array_unique(array_column($owners, 'campus_id'));
        $isCatalog = collect($owners)->every(fn (array $owner): bool => in_array($owner['type'], $catalogTypes, true));
        $activity->properties = ($activity->properties ?? collect())->put('address_context', [
            'owners' => $owners,
            'campus_id' => $isCatalog && count($campusIds) === 1 ? reset($campusIds) : null,
            'administrative' => collect($owners)->every(fn (array $owner): bool => $owner['type'] === (new Campus)->getMorphClass()),
        ]);
    }

    /** @param Builder<Activity> $query */
    public function apply(Builder $query, ?int $campusId = null): void
    {
        $query->where('subject_type', (new Address)->getMorphClass())
            ->where(function (Builder $query) use ($campusId): void {
                $query->where(function (Builder $query) use ($campusId): void {
                    if ($campusId === null) {
                        $query->where('properties->address_context->administrative', true);
                    } else {
                        $query->where('properties->address_context->campus_id', $campusId);
                    }
                })->orWhere(function (Builder $query) use ($campusId): void {
                    $query->whereNull('properties->address_context');
                    $query->whereExists(function (QueryBuilder $history) use ($campusId): void {
                        $this->ownerHistory($history);
                        $history->where(fn (QueryBuilder $allowed) => $this->allowedOwner($allowed, $campusId));
                    })->whereNotExists(function (QueryBuilder $history) use ($campusId): void {
                        $this->ownerHistory($history);
                        $history->whereNot(fn (QueryBuilder $allowed) => $this->allowedOwner($allowed, $campusId));
                    });
                    foreach ([UserPersonalData::class => ['address_id'], Internship::class => ['student_address_id', 'workplace_address_id']] as $model => $fields) {
                        $table = (new $model)->getTable();
                        $query->whereNotExists(function (QueryBuilder $references) use ($table, $fields): void {
                            $references->selectRaw('1')->from($table)->where(function (QueryBuilder $references) use ($table, $fields): void {
                                foreach ($fields as $field) {
                                    $references->orWhereColumn($table.'.'.$field, (new Activity)->getTable().'.subject_id');
                                }
                            });
                        });
                    }
                });
            });
    }

    private function ownerHistory(QueryBuilder $query): QueryBuilder
    {
        /** @var literal-string $table */
        $table = (new Activity)->getTable();

        return $query->selectRaw('1')->from($table.' as address_owner_history')
            ->whereIn('address_owner_history.subject_type', [(new Campus)->getMorphClass(), (new GrantingParty)->getMorphClass()])
            ->where(function (QueryBuilder $query) use ($table): void {
                foreach (['attributes', 'old'] as $snapshot) {
                    $query->orWhereRaw("address_owner_history.attribute_changes->?->>'address_id' = \"{$table}\".subject_id::text", [$snapshot]);
                }
            });
    }

    private function allowedOwner(QueryBuilder $query, ?int $campusId): void
    {
        $query->where(function (QueryBuilder $query) use ($campusId): void {
            $query->where('address_owner_history.subject_type', (new Campus)->getMorphClass());
            if ($campusId !== null) {
                $query->where('address_owner_history.subject_id', $campusId);
            }
        });
        if ($campusId !== null) {
            $query->orWhere(function (QueryBuilder $query) use ($campusId): void {
                $query->where('address_owner_history.subject_type', (new GrantingParty)->getMorphClass())
                    ->where(function (QueryBuilder $query) use ($campusId): void {
                        $query->whereIn('address_owner_history.subject_id', GrantingParty::query()->where('campus_id', $campusId)->select('id'))
                            ->orWhereIn('address_owner_history.subject_id', Activity::query()
                                ->where('subject_type', (new GrantingParty)->getMorphClass())
                                ->where(fn (Builder $query) => $query->where('attribute_changes->attributes->campus_id', $campusId)
                                    ->orWhere('attribute_changes->old->campus_id', $campusId))->select('subject_id'));
                    });
            });
        }
    }

    /** @return list<array{type: string, id: int, name: string, campus_id: int|null}> */
    public function forActivity(Activity $activity): array
    {
        $recorded = $activity->properties?->get('address_context');
        $owners = [];
        if (is_array($recorded)) {
            foreach ($recorded['owners'] ?? [] as $value) {
                $snapshot = $this->ownerSnapshot($value);
                if ($snapshot !== null) {
                    $owners[] = $snapshot;
                }
            }

            return $owners;
        }
        $events = $this->preparedOwnerEvents?->filter(fn (Activity $owner): bool => data_get($owner->attribute_changes, 'attributes.address_id') === $activity->subject_id
            || data_get($owner->attribute_changes, 'old.address_id') === $activity->subject_id)
            ?? Activity::query()->whereIn('subject_type', [(new Campus)->getMorphClass(), (new GrantingParty)->getMorphClass()])
                ->where(fn (Builder $query) => $query->where('attribute_changes->attributes->address_id', $activity->subject_id)
                    ->orWhere('attribute_changes->old->address_id', $activity->subject_id))
                ->orderBy('id')->get();

        foreach ($events->groupBy(fn (Activity $owner): string => $owner->subject_type.':'.$owner->subject_id) as $ownerEvents) {
            $owner = $ownerEvents->last(fn (Activity $event): bool => $event->id <= $activity->id) ?? $ownerEvents->first();
            $snapshot = $this->ownerSnapshot($owner->properties?->get('address_owner'));
            if ($snapshot !== null) {
                $owners[] = $snapshot;

                continue;
            }
            $name = data_get($owner->attribute_changes, 'attributes.name') ?? data_get($owner->attribute_changes, 'old.name') ?? '';
            $campusId = $owner->subject_type === (new Campus)->getMorphClass() ? $owner->subject_id
                : (data_get($owner->attribute_changes, 'attributes.campus_id') ?? data_get($owner->attribute_changes, 'old.campus_id')
                    ?? GrantingParty::query()->whereKey($owner->subject_id)->value('campus_id'));
            $snapshot = $this->ownerSnapshot(['type' => $owner->subject_type, 'id' => $owner->subject_id, 'name' => $name, 'campus_id' => $campusId]);
            if ($snapshot !== null) {
                $owners[] = $snapshot;
            }
        }

        return $owners;
    }

    /** @return array{type: string, id: int, name: string, campus_id: int|null}|null */
    private function ownerSnapshot(mixed $value): ?array
    {
        if (! is_array($value) || ! is_string($value['type'] ?? null) || ! is_int($value['id'] ?? null)
            || ! is_string($value['name'] ?? null) || ! array_key_exists('campus_id', $value)
            || ($value['campus_id'] !== null && ! is_int($value['campus_id']))) {
            return null;
        }

        return ['type' => $value['type'], 'id' => $value['id'], 'name' => $value['name'], 'campus_id' => $value['campus_id']];
    }
}
