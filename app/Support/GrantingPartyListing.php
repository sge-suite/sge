<?php

namespace App\Support;

use App\Enums\BrazilianState;
use App\Models\Affiliation;
use App\Models\City;
use App\Models\GrantingParty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as LaravelValidator;

class GrantingPartyListing
{
    /** @param array<string, mixed> $filters */
    public function validator(array $filters): LaravelValidator
    {
        return Validator::make($filters, [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['all', 'active', 'inactive'])],
            'state' => ['nullable', Rule::in(['all', ...BrazilianState::values()])],
            'city' => ['nullable', 'integer', 'min:1'],
            'course' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<GrantingParty>
     */
    public function baseQuery(Affiliation $affiliation, array $filters): Builder
    {
        $status = $filters['status'] ?? 'all';
        $stateValue = $filters['state'] ?? 'all';
        $state = $stateValue === 'all' ? null : BrazilianState::tryFrom((string) $stateValue);
        $cityId = filter_var($filters['city'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $selectedCity = $cityId === false ? null : City::query()
            ->when($state !== null, fn (Builder $query) => $query->forState($state))
            ->find($cityId);
        $courseId = filter_var($filters['course'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return GrantingParty::query()->where('campus_id', $affiliation->campus_id)
            ->with('address.city')
            ->when($courseId !== false, fn (Builder $query) => $query->withStartedInternshipsForCourse((int) $courseId))
            ->when($status === 'active', fn (Builder $query) => $query->whereNull('deactivated_at'))
            ->when($status === 'inactive', fn (Builder $query) => $query->whereNotNull('deactivated_at'))
            ->when($state !== null || $selectedCity !== null, function (Builder $query) use ($state, $selectedCity): void {
                $query->whereHas('address.city', function (Builder $query) use ($state, $selectedCity): void {
                    if ($state !== null) {
                        $query->where('state', $state->value);
                    }

                    if ($selectedCity !== null) {
                        $query->whereKey($selectedCity->getKey());
                    }
                });
            });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<GrantingParty>
     */
    public function exportQuery(Affiliation $affiliation, array $filters): Builder
    {
        $query = $this->baseQuery($affiliation, $filters);
        $search = mb_substr(trim((string) ($filters['search'] ?? '')), 0, 255);

        if ($search !== '') {
            $status = $filters['status'] ?? 'all';
            $stateValue = $filters['state'] ?? 'all';
            $state = $stateValue === 'all' ? null : BrazilianState::tryFrom((string) $stateValue);
            $cityId = filter_var($filters['city'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $selectedCity = $cityId === false ? null : City::query()
                ->when($state !== null, fn (Builder $query) => $query->forState($state))
                ->find($cityId);

            $ids = GrantingParty::search($search)
                ->where('campus_id', (int) $affiliation->campus_id)
                ->options(['attributesToSearchOn' => ['name', 'document_number', 'normalized_document_number']])
                ->when($status !== 'all', fn ($searchQuery) => $searchQuery->where('is_active', (int) ($status === 'active')))
                ->when($state !== null, fn ($searchQuery) => $searchQuery->where('state', $state->value))
                ->when($selectedCity !== null, fn ($searchQuery) => $searchQuery->where('city_id', (int) $selectedCity->id))
                ->take(1000)
                ->keys()
                ->map(fn ($id): int => (int) $id)
                ->all();

            $query->whereIntegerInRaw('granting_parties.id', $ids);
        }

        return $query->orderBy('name')->orderBy('id');
    }
}
