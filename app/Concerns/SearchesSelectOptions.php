<?php

namespace App\Concerns;

use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\GrantingParty;
use App\Models\InternshipType;
use Livewire\Attributes\Renderless;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;

trait SearchesSelectOptions
{
    /** @return array{model: class-string<Affiliation|Campus|Course|GrantingParty|InternshipType>, options: array<int|string, string>, attributes?: list<string>} */
    abstract protected function selectSearchSource(string $source): array;

    /** @return array{keys: list<string>, hasMore: bool, unavailable?: bool} */
    #[Renderless]
    public function searchSelectOptions(string $source, string $term): array
    {
        abort_if(strlen($source) > 80 || mb_strlen($term) > 255, 422);
        try {
            $configuration = $this->selectSearchSource($source);
            $ids = [];
            foreach (array_keys($configuration['options']) as $key) {
                $id = filter_var($key, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id !== false) {
                    $ids[] = $id;
                }
            }
            if ($ids === []) {
                return ['keys' => [], 'hasMore' => false];
            }

            $model = $configuration['model'];
            $query = $model::search(mb_substr(trim($term), 0, 255))->whereIn('id', $ids);
            if (isset($configuration['attributes'])) {
                $query->options(['attributesToSearchOn' => $configuration['attributes']]);
            }
            $keys = $query->take(21)->keys()
                ->map(fn (int|string $key): string => (string) $key)->values()->all();

            return ['keys' => array_values(array_slice($keys, 0, 20)), 'hasMore' => count($keys) > 20];
        } catch (ApiException|CommunicationException $exception) {
            report($exception);

            return ['keys' => [], 'hasMore' => false, 'unavailable' => true];
        }
    }
}
