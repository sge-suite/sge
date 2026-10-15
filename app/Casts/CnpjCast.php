<?php

declare(strict_types=1);

namespace App\Casts;

use App\Helpers\BrazilianDocumentHelper;
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

        $cnpj = BrazilianDocumentHelper::normalizeCnpj((string) $value);

        if (preg_match('/^[A-Z0-9]{12}[0-9]{2}$/', $cnpj) !== 1 || ! (new Cnpj)->passes($key, $cnpj)) {
            throw new InvalidArgumentException("O CNPJ '{$value}' informado é inválido.");
        }

        return $cnpj;
    }
}
