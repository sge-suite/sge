<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LaravelLegends\PtBrValidator\Rules\Cpf;

/**
 * @implements CastsAttributes<string, string>
 */
class CpfCast implements CastsAttributes
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
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $digits = unmask((string) $value);

        if (! (new Cpf)->passes($key, $digits)) {
            throw new InvalidArgumentException("O CPF '{$value}' informado é inválido.");
        }

        return $digits;
    }
}
