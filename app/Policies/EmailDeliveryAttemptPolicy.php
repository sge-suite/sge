<?php

namespace App\Policies;

use App\Enums\AffiliationType;
use App\Models\EmailDeliveryAttempt;
use App\Models\User;
use App\Support\ActiveAffiliationContext;
use App\Support\EmailLogAccess;

class EmailDeliveryAttemptPolicy
{
    public function __construct(private EmailLogAccess $access, private ActiveAffiliationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->access->forCurrentContext($user, app('session.store')) !== null;
    }

    public function view(User $user, EmailDeliveryAttempt $attempt): bool
    {
        return $this->access->forCurrentContext($user, app('session.store'))?->whereKey($attempt->id)->exists() === true;
    }

    public function retry(User $user, EmailDeliveryAttempt $attempt): bool
    {
        $affiliation = $this->context->currentFor($user, app('session.store'));

        return match ($affiliation?->type) {
            AffiliationType::SystemAdministrator => $this->view($user, $attempt),
            AffiliationType::InternshipOffice => $affiliation->campus()->whereNull('deactivated_at')->exists()
                && $this->view($user, $attempt),
            default => false,
        };
    }
}
