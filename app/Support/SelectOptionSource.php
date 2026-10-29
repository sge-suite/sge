<?php

namespace App\Support;

use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\GrantingParty;
use App\Models\InternshipType;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Meilisearch\Endpoints\Indexes;

/** @template TModel of Affiliation|Campus|Course|GrantingParty|InternshipType */
final class SelectOptionSource
{
    /**
     * @param  Builder<TModel>  $query
     * @param  Closure(TModel): string  $label
     * @param  array<string, 'asc'|'desc'>  $sort
     * @param  list<string>|null  $attributes
     */
    public function __construct(
        public readonly Builder $query,
        private readonly string $filter,
        private readonly Closure $label,
        private readonly array $sort = ['name' => 'asc', 'id' => 'asc'],
        private readonly ?array $attributes = null,
    ) {}

    /** @return array<int|string, string> */
    public function selected(mixed $id): array
    {
        if (! is_string($id) && ! is_int($id)) {
            return [];
        }
        $key = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $record = $key === false ? null : (clone $this->query)->find($key);

        return $record === null ? [] : [$record->id => ($this->label)($record)];
    }

    /** @return array{options: list<array{value: string, label: string}>, hasMore: bool} */
    public function results(string $term): array
    {
        if ($term === '') {
            /** @var Collection<int, TModel> $records */
            $records = (clone $this->query)->limit(21)->get();

            return $this->present($records);
        }

        $model = $this->query->getModel();
        $search = $model::search($term, function (Indexes $index, string $query, array $options): array {
            $options['filter'] = array_values(array_filter([$options['filter'] ?? null, $this->filter]));

            return $index->rawSearch($query, $options);
        });
        foreach ($this->sort as $attribute => $direction) {
            $search->orderBy($attribute, $direction);
        }

        $records = new Collection;
        $page = 1;
        do {
            $options = ['page' => $page, 'attributesToRetrieve' => ['id']];
            if ($this->attributes !== null) {
                $options['attributesToSearchOn'] = $this->attributes;
            }
            $result = app(SearchQuery::class)->run(fn () => $search->options($options)->take(21)->raw());
            $ids = array_column($result['hits'], 'id');
            $eligible = (clone $this->query)->whereKey($ids)->limit(21)->get()->keyBy('id');
            foreach ($ids as $id) {
                if (isset($eligible[$id]) && ! $records->contains('id', $id)) {
                    $records->push($eligible[$id]);
                }
                if ($records->count() === 21) {
                    break;
                }
            }
            $page++;
        } while ($records->count() < 21 && $ids !== [] && $page <= ($result['totalPages'] ?? 1));

        return $this->present($records);
    }

    /**
     * @param  Collection<int, TModel>  $records
     * @return array{options: list<array{value: string, label: string}>, hasMore: bool}
     */
    private function present(Collection $records): array
    {
        return ['options' => array_values($records->take(20)->map(fn ($record): array => [
            'value' => (string) $record->id, 'label' => ($this->label)($record),
        ])->all()), 'hasMore' => $records->count() > 20];
    }
}
