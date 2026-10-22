<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Enums\EmailMessagePurpose;
use App\Models\EmailDeliveryAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CampusEmailLogScope
{
    public function __construct(private AdministrativeEmailLogScope $administrativeScope, private OperationalEmailLogScope $operationalScope) {}

    /** @return list<EmailMessagePurpose> */
    public function purposes(): array
    {
        return [...$this->administrativeScope->purposes(), ...$this->operationalScope->purposes()];
    }

    /**
     * @param  Builder<EmailDeliveryAttempt>  $query
     * @return Builder<EmailDeliveryAttempt>
     */
    public function apply(Builder $query, int $campusId, User $actor): Builder
    {
        return $query->where(function (Builder $query) use ($campusId, $actor): void {
            $query->where(function (Builder $query) use ($campusId, $actor): void {
                $this->administrative($query, $campusId, $actor);
            })->orWhere(function (Builder $query) use ($campusId): void {
                $this->operationalScope->apply($query, $campusId);
            });
        });
    }

    /**
     * @param  Builder<EmailDeliveryAttempt>  $query
     * @return Builder<EmailDeliveryAttempt>
     */
    private function administrative(Builder $query, int $campusId, User $actor): Builder
    {
        return $query->whereIn('purpose', $this->administrativeScope->purposes())
            ->whereJsonContains('scope_context->campus_ids', $campusId)
            ->where(function (Builder $query): void {
                foreach ([...array_diff(AffiliationType::values(), [AffiliationType::SystemAdministrator->value]), AffiliationType::LEGACY_CAMPUS_ADMINISTRATOR] as $type) {
                    $query->orWhereJsonContains('scope_context->affiliation_types', $type);
                }
            })
            ->where(function (Builder $query) use ($actor): void {
                $query->whereJsonDoesntContain('scope_context->affiliation_types', AffiliationType::SystemAdministrator->value)
                    ->orWhere(function (Builder $query) use ($actor): void {
                        $query->where('purpose', EmailMessagePurpose::AccountEmailChanged)
                            ->where('scope_context->user_id', $actor->id);
                    });
            });
    }
}
