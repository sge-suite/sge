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
        $phoneForValidation = preg_match('/^[0-9]{10,11}$/', $phone) === 1
            ? sprintf('(%s) %s-%s', substr($phone, 0, 2), substr($phone, 2, -4), substr($phone, -4))
            : $phone;

        if (! (new CelularComDdd)->passes($key, $phoneForValidation)) {
            throw new InvalidArgumentException("O telefone '{$value}' informado é inválido.");
        }

        return DigitsHelper::only($phone);
    }
}
