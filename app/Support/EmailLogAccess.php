<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Enums\EmailMessagePurpose;
use App\Models\EmailDeliveryAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Session\Store;

class EmailLogAccess
{
    public function __construct(
        private ActiveAffiliationContext $context,
        private AdministrativeEmailLogScope $administrativeScope,
    ) {}

    /** @return array<string, string> */
    public function purposeOptionsForCurrentContext(User $user, Store $session): array
    {
        $affiliation = $this->context->currentFor($user, $session);
        $purposes = match ($affiliation?->type) {
            AffiliationType::SystemAdministrator => $this->administrativeScope->purposes(),
            default => [],
        };

        return collect($purposes)->mapWithKeys(fn (EmailMessagePurpose $purpose): array => [$purpose->value => $purpose->label()])->all();
    }

    /** @return Builder<EmailDeliveryAttempt>|null */
    public function forCurrentContext(User $user, Store $session): ?Builder
    {
        $affiliation = $this->context->currentFor($user, $session);

        return match ($affiliation?->type) {
            AffiliationType::SystemAdministrator => $this->administrativeScope->apply(EmailDeliveryAttempt::query()),
            default => null,
        };
    }
}
