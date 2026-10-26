<?php

namespace App\Support;

use App\Models\Affiliation;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Activitylog\Support\CauserResolver;

class ActiveAffiliationContext
{
    private const SESSION_KEY = 'active_affiliation_id';

    private const NEEDS_CHOICE_KEY = 'active_affiliation_needs_choice';

    public function __construct(private CauserResolver $causerResolver) {}

    /** @return Collection<int, Affiliation> */
    public function availableFor(User $user): Collection
    {
        return $user->affiliations()->active()->with(['campus', 'course'])->orderByLastUsedAt()->get();
    }

    public function resolve(User $user, Session $session): ?Affiliation
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

    public function currentFor(User $user, Session $session): ?Affiliation
    {
        return $this->resolve($user, $session);
    }

    public function switchToOther(User $user, Session $session): ?Affiliation
    {
        $current = $this->currentFor($user, $session);
        $available = $this->availableFor($user);
        if ($current === null || $available->count() !== 2) {
            return null;
        }
        $other = $available->first(fn (Affiliation $affiliation): bool => $affiliation->id !== $current->id);

        return $other === null ? null : $this->select($user, $session, $other->id);
    }

    public function select(User $user, Session $session, int $affiliationId): ?Affiliation
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
