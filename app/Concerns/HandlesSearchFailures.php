<?php

namespace App\Concerns;

use App\Support\PageSize;
use App\Support\SearchQuery;
use App\Support\SearchUnavailable;
use Closure;
use Illuminate\Pagination\LengthAwarePaginator;

trait HandlesSearchFailures
{
    /** @var array<string, true> */
    protected array $searchFailures = [];

    /** @return array<string, list<string>> */
    abstract protected function searchRetryProperties(): array;

    public function searchFailed(string $source): bool
    {
        return isset($this->searchFailures[$source]);
    }

    public function retrySearch(string $source): void
    {
        $properties = $this->searchRetryProperties();
        abort_unless(isset($properties[$source]), 404);
        foreach ($properties[$source] as $property) {
            unset($this->{$property});
        }
        unset($this->searchFailures[$source]);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $query
     * @param  Closure(): T  $unavailable
     * @return T
     */
    protected function searchWithRecovery(string $source, Closure $query, Closure $unavailable): mixed
    {
        try {
            $result = app(SearchQuery::class)->run($query);
            unset($this->searchFailures[$source]);

            return $result;
        } catch (SearchUnavailable) {
            $this->searchFailures[$source] = true;

            return $unavailable();
        }
    }

    /** @return LengthAwarePaginator<int, never> */
    protected function unavailableSearchPage(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, PageSize::PER_PAGE);
    }
}
