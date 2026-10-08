<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Enums\EmailMessagePurpose;
use App\Models\EmailDeliveryAttempt;
use Illuminate\Database\Eloquent\Builder;

class AdministrativeEmailLogScope
{
    /** @return list<EmailMessagePurpose> */
    public function purposes(): array
    {
        return [EmailMessagePurpose::AccountCreated, EmailMessagePurpose::NewAffiliation, EmailMessagePurpose::AccountEmailChanged, EmailMessagePurpose::AdministrativeChange];
    }

    /**
     * @param  Builder<EmailDeliveryAttempt>  $query
     * @return Builder<EmailDeliveryAttempt>
     */
    public function apply(Builder $query): Builder
    {
        return $query->whereIn('purpose', $this->purposes())->where(function (Builder $query): void {
            $query->whereJsonContains('scope_context->affiliation_types', AffiliationType::SystemAdministrator->value)
                ->orWhereJsonContains('scope_context->affiliation_types', AffiliationType::CampusAdministrator->value);
        });
    }
}
