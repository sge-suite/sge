<?php

namespace App\Concerns;

use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\GrantingParty;
use App\Models\InternshipType;
use App\Support\SearchQuery;
use App\Support\SearchUnavailable;
use App\Support\SelectOptionSource;
use Livewire\Attributes\Renderless;

trait SearchesSelectOptions
{
    /** @return SelectOptionSource<*>|array{model: class-string<Affiliation|Campus|Course|GrantingParty|InternshipType>, options: array<int|string, string>, attributes?: list<string>} */
    abstract protected function selectSearchSource(string $source): SelectOptionSource|array;

    /** @return array<int|string, string> */
    protected function selectedSelectOptions(string $source, mixed $id): array
    {
        $configuration = $this->selectSearchSource($source);
        abort_unless($configuration instanceof SelectOptionSource, 500);

        return $configuration->selected($id);
    }

    /** @return array{options: list<array{value: string, label: string}>, hasMore: bool, unavailable?: bool} */
    #[Renderless]
    public function searchSelectOptions(string $source, string $term): array
    {
        abort_if(strlen($source) > 80 || mb_strlen($term) > 255, 422);
        try {
            $configuration = $this->selectSearchSource($source);
            if ($configuration instanceof SelectOptionSource) {
                return $configuration->results(trim($term));
            }
            $ids = [];
            foreach (array_keys($configuration['options']) as $key) {
                $id = filter_var($key, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id !== false) {
                    $ids[] = $id;
                }
            }
            if ($ids === []) {
                return ['options' => [], 'hasMore' => false];
            }

            $model = $configuration['model'];
            $query = $model::search(mb_substr(trim($term), 0, 255))->whereIn('id', $ids);
            if (isset($configuration['attributes'])) {
                $query->options(['attributesToSearchOn' => $configuration['attributes']]);
            }
            $keys = app(SearchQuery::class)->run(fn () => $query->take(21)->keys())
                ->map(fn (int|string $key): string => (string) $key)->values()->all();

            return ['options' => array_map(fn ($key): array => ['value' => $key, 'label' => $configuration['options'][$key]], array_values(array_slice($keys, 0, 20))), 'hasMore' => count($keys) > 20];
        } catch (SearchUnavailable) {
            return ['options' => [], 'hasMore' => false, 'unavailable' => true];
        }
    }
}
