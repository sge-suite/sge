<?php

namespace App\Support;

use App\Models\Affiliation;
use App\Models\User;

class EmailDeliveryContext
{
    /** @return array{user_id: int, affiliation_ids: list<int>, affiliation_types: list<string>, campus_ids: list<int>} */
    public function forAffiliation(Affiliation $affiliation): array
    {
        return [
            'user_id' => $affiliation->user_id,
            'affiliation_ids' => [$affiliation->id],
            'affiliation_types' => [$affiliation->type->value],
            'campus_ids' => $affiliation->campus_id === null ? [] : [$affiliation->campus_id],
        ];
    }

    /** @return array{user_id: int, affiliation_ids: list<int>, affiliation_types: list<string>, campus_ids: list<int>} */
    public function forUser(User $user): array
    {
        $affiliations = $user->affiliations()->orderBy('id')->get(['id', 'type', 'campus_id']);
        $affiliationIds = [];
        $affiliationTypes = [];
        $campusIds = [];

        foreach ($affiliations as $affiliation) {
            $affiliationIds[] = $affiliation->id;
            $affiliationTypes[] = $affiliation->type->value;

            if ($affiliation->campus_id !== null) {
                $campusIds[] = $affiliation->campus_id;
            }
        }

        return [
            'user_id' => $user->id,
            'affiliation_ids' => $affiliationIds,
            'affiliation_types' => array_values(array_unique($affiliationTypes)),
            'campus_ids' => array_values(array_unique($campusIds)),
        ];
    }
}
