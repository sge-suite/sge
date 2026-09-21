<?php

namespace App\Casts;

use App\Helpers\DigitsHelper;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LaravelLegends\PtBrValidator\Rules\CelularComDdd;

/**
 * @implements CastsAttributes<string, string>
 */
class PhoneCast implements CastsAttributes
{
    /**
     * Cast the given value when reading from database.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value !== null ? (string) $value : null;
    }

    /**
     * Validate a Brazilian telephone with DDD and prepare it for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (blank($value)) {
            return null;
        }

        $phone = trim((string) $value);

        if (! (new CelularComDdd)->passes($key, $phone)) {
            throw new InvalidArgumentException("O telefone '{$value}' informado é inválido.");
        }

        return DigitsHelper::only($phone);
    }
}
