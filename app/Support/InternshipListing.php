<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Enums\InternshipCancellationRequestStatus;
use App\Enums\InternshipStatus;
use App\Models\Affiliation;
use App\Models\Internship;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as LaravelValidator;
use Laravel\Scout\Builder as SearchBuilder;

class InternshipListing
{
    public const ORDERS = [
        'updated' => 'Atualizados recentemente',
        'projected_end_asc' => 'Término previsto mais próximo',
        'projected_end_desc' => 'Término previsto mais distante',
    ];

    /** @param array<string, mixed> $filters */
    public function validator(array $filters): LaravelValidator
    {
        return Validator::make($filters, [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['all', ...array_keys(InternshipStatus::options())])],
            'course' => ['nullable', 'integer', 'min:1'],
            'advisor' => ['nullable', 'integer', 'min:1'],
            'granting_party' => ['nullable', 'integer', 'min:1'],
            'pending_cancellations' => ['nullable', 'boolean'],
            'projected_end_from' => ['bail', 'nullable', 'date_format:Y-m-d', 'date'],
            'projected_end_to' => ['bail', 'nullable', 'date_format:Y-m-d', 'date',
                Rule::when(filled($filters['projected_end_from'] ?? null), ['after_or_equal:projected_end_from'])],
            'sort' => ['nullable', Rule::in(array_keys(self::ORDERS))],
        ], [
            'projected_end_from.date_format' => 'Informe uma data inicial válida para o término previsto.',
            'projected_end_from.date' => 'Informe uma data inicial válida para o término previsto.',
            'projected_end_to.date_format' => 'Informe uma data final válida para o término previsto.',
            'projected_end_to.date' => 'Informe uma data final válida para o término previsto.',
            'projected_end_to.after_or_equal' => 'A data final do término previsto deve ser igual ou posterior à inicial.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Internship>
     */
    public function query(Affiliation $affiliation, array $filters): Builder
    {
        $query = Internship::query()->visibleToAffiliation($affiliation);
        $office = $affiliation->type === AffiliationType::InternshipOffice;

        $status = $filters['status'] ?? 'all';
        $searchFilters = array_filter([
            'status' => $status === 'all' ? null : $status,
            'course_id' => $filters['course'] ?? null,
            'advisor_affiliation_id' => $filters['advisor'] ?? null,
            'granting_party_id' => $filters['granting_party'] ?? null,
        ]);
        foreach ($searchFilters as $column => $value) {
            $query->where($column, $value);
        }

        $pendingOnly = $office && ($filters['pending_cancellations'] ?? false);
        if ($office) {
            $pending = fn (Builder $requests) => $requests->whereIn('status', [
                InternshipCancellationRequestStatus::Submitted, InternshipCancellationRequestStatus::UnderReview,
            ]);
            $query->withCount(['cancellationRequests as pending_cancellations_count' => $pending]);
            if ($pendingOnly) {
                $query->whereHas('cancellationRequests', $pending);
            }
        }

        $query->when($filters['projected_end_from'] ?? null, fn (Builder $query, string $date) => $query->where('projected_end_date', '>=', $date))
            ->when($filters['projected_end_to'] ?? null, fn (Builder $query, string $date) => $query->where('projected_end_date', '<=', $date));

        $term = trim($filters['search'] ?? '');
        if ($term !== '') {
            $search = Internship::searchVisibleToAffiliation($term, $affiliation);
            foreach ($searchFilters as $column => $value) {
                $search->where($column, $column === 'status' ? $value : (int) $value);
            }
            if ($pendingOnly) {
                $search->where('has_pending_cancellation', 1);
            }

            $ids = $this->searchIds($search, $query);
            $query->whereIntegerInRaw('internships.id', $ids);
        }

        return match ($filters['sort'] ?? 'updated') {
            'projected_end_asc' => $query->orderByRaw('projected_end_date ASC NULLS LAST')->orderByDesc('id'),
            'projected_end_desc' => $query->orderByRaw('projected_end_date DESC NULLS LAST')->orderByDesc('id'),
            default => $query->latest('updated_at')->latest('id'),
        };
    }

    /**
     * @param  SearchBuilder<Internship>  $search
     * @param  Builder<Internship>  $query
     * @return list<int>
     */
    private function searchIds(SearchBuilder $search, Builder $query): array
    {
        $limit = (int) config('scout.meilisearch.index-settings.'.Internship::class.'.pagination.maxTotalHits', 1000);
        $search->options([...$search->options, 'attributesToRetrieve' => ['id']])->take($limit);
        $ids = $search->keys();

        if ($ids->count() < $limit) {
            return array_values($ids->map(fn ($id): int => (int) $id)->all());
        }

        // The regular search result window is capped, so search the eligible IDs in batches.
        $id = $query->qualifyColumn('id');

        $ids = (clone $query)->select($id)->toBase()->lazyById($limit, $id, 'id')->chunk($limit)
            ->flatMap(fn ($batch) => (clone $search)->whereIn('id', $batch->pluck('id')->all())->keys())
            ->map(fn ($id): int => (int) $id)->all();

        return array_values($ids);
    }
}
