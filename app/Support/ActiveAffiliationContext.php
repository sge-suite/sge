<?php

namespace App\Support;

use App\Models\Affiliation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Session\Store;
use Spatie\Activitylog\Support\CauserResolver;

class ActiveAffiliationContext
{
    private const SESSION_KEY = 'active_affiliation_id';

    private const NEEDS_CHOICE_KEY = 'active_affiliation_needs_choice';

    public function __construct(private CauserResolver $causerResolver) {}

    /** @return Collection<int, Affiliation> */
    public function availableFor(User $user): Collection
    {
        return $user->affiliations()->active()->with('campus')->orderByLastUsedAt()->get();
    }

    public function resolve(User $user, Store $session): ?Affiliation
    {
        $selectedId = $session->get(self::SESSION_KEY);

        if ($session->get(self::NEEDS_CHOICE_KEY) === true) {
            return null;
        }

        if ($selectedId !== null) {
            $affiliation = (is_int($selectedId) || (is_string($selectedId) && ctype_digit($selectedId)))
                ? $user->affiliations()->active()->find((int) $selectedId)
                : null;

            if ($affiliation !== null) {
                return $affiliation;
            }

            $session->forget(self::SESSION_KEY);
            $session->put(self::NEEDS_CHOICE_KEY, true);

            return null;
        }

        $available = $this->availableFor($user);
        $mostRecent = $available->first();
        $nextMostRecent = $available->get(1);
        $hasUniqueMostRecent = $mostRecent?->last_used_at !== null
            && ($nextMostRecent?->last_used_at === null
                || ! $mostRecent->last_used_at->equalTo($nextMostRecent->last_used_at));

        if ($available->count() === 1 || $hasUniqueMostRecent) {
            $affiliation = $mostRecent;
            $session->put(self::SESSION_KEY, $affiliation->id);

            return $affiliation;
        }

        return null;
    }

    public function currentFor(User $user, Store $session): ?Affiliation
    {
        return $this->resolve($user, $session);
    }

    public function select(User $user, Store $session, int $affiliationId): ?Affiliation
    {
        $affiliation = $user->affiliations()->active()->find($affiliationId);

        if ($affiliation === null) {
            return null;
        }

        if (! $this->causerResolver->withCauser($affiliation, fn (): bool => $affiliation->markAsUsed())) {
            return null;
        }

        $session->put(self::SESSION_KEY, $affiliation->id);
        $session->forget(self::NEEDS_CHOICE_KEY);

        return $affiliation;
    }
}
