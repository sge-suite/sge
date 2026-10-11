<?php

namespace App\Casts;

use App\Enums\AffiliationType;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<AffiliationType, AffiliationType|string> */
class AffiliationTypeCast implements CastsAttributes, SerializesCastableAttributes
{
    public bool $withoutObjectCaching = true;

    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?AffiliationType
    {
        if ($value === null) {
            return null;
        }

        return AffiliationType::from($value === AffiliationType::LEGACY_CAMPUS_ADMINISTRATOR
            ? AffiliationType::InternshipOffice->value : $value);
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof AffiliationType ? $value->value : AffiliationType::from($value)->value;
    }

    /** @param array<string, mixed> $attributes */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value?->value;
    }
}
