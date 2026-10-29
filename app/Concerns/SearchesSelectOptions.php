<?php

namespace App\Concerns;

use App\Support\SearchUnavailable;
use App\Support\SelectOptionSource;
use Livewire\Attributes\Renderless;

trait SearchesSelectOptions
{
    /** @return SelectOptionSource<*> */
    abstract protected function selectSearchSource(string $source): SelectOptionSource;

    /** @return array<int|string, string> */
    protected function selectedSelectOptions(string $source, mixed $id): array
    {
        return $this->selectSearchSource($source)->selected($id);
    }

    /** @return array{options: list<array{value: string, label: string}>, hasMore: bool, unavailable?: bool, incomplete?: bool} */
    #[Renderless]
    public function searchSelectOptions(string $source, string $term): array
    {
        abort_if(strlen($source) > 80 || mb_strlen($term) > 255, 422);
        try {
            return $this->selectSearchSource($source)->results(trim($term));
        } catch (SearchUnavailable) {
            return ['options' => [], 'hasMore' => false, 'unavailable' => true];
        }
    }
}
