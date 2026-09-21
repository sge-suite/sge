<?php

declare(strict_types=1);

namespace App\Casts;

use App\Helpers\DigitsHelper;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LaravelLegends\PtBrValidator\Rules\Cnpj;

/**
 * @implements CastsAttributes<string, string>
 */
class CnpjCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value !== null ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (blank($value)) {
            return null;
        }

        $cnpj = DigitsHelper::only((string) $value);

        if (! (new Cnpj)->passes($key, $cnpj)) {
            throw new InvalidArgumentException("O CNPJ '{$value}' informado é inválido.");
        }

        return $cnpj;
    }
}
