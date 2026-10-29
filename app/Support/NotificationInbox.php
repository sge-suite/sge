<?php

namespace App\Support;

use App\Models\Affiliation;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

class NotificationInbox
{
    public function __construct(private ActiveAffiliationContext $context) {}

    public function affiliation(User $actor, ?int $expectedAffiliationId = null): Affiliation
    {
        $affiliation = $this->context->currentFor($actor, app('session.store'));
        abort_unless($affiliation !== null, 403);
        abort_if($expectedAffiliationId !== null && $affiliation->id !== $expectedAffiliationId,
            403, 'O vínculo selecionado mudou. Reabra a central de notificações.');
        Gate::forUser($actor)->authorize('viewNotifications', $affiliation);

        return $affiliation;
    }

    /** @return MorphMany<DatabaseNotification, Affiliation> */
    public function query(User $actor, int $expectedAffiliationId): MorphMany
    {
        return $this->affiliation($actor, $expectedAffiliationId)->notifications();
    }
}
