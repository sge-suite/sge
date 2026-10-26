<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Helpers\BrazilianDocumentHelper;
use App\Helpers\DigitsHelper;
use App\Models\Affiliation;
use App\Models\GrantingParty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class InternshipRegistrationLookup
{
    /** @return Builder<GrantingParty> */
    public function grantingParties(Affiliation $owner): Builder
    {
        return GrantingParty::query()->active()->where('campus_id', $owner->campus_id);
    }

    /** @return Builder<Affiliation> */
    public function supervisors(Affiliation $owner): Builder
    {
        return Affiliation::query()->active()->where('campus_id', $owner->campus_id)
            ->where('type', AffiliationType::Supervisor->value);
    }

    /** @return Collection<int, GrantingParty> */
    public function grantingPartyMatches(Affiliation $owner, string $document): Collection
    {
        return $this->grantingParties($owner)->where('document_number', BrazilianDocumentHelper::normalizeCnpj($document))
            ->with('address.city')->orderBy('name')->orderBy('id')->get();
    }

    public function supervisorMatch(Affiliation $owner, string $cpf): ?Affiliation
    {
        return $this->supervisors($owner)->whereHas('user', fn (Builder $query) => $query->where('cpf', DigitsHelper::only($cpf)))
            ->with('user.personalData')->orderBy('id')->first();
    }
}
