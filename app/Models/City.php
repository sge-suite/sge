<?php

namespace App\Models;

use App\Enums\BrazilianState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $ibge_code
 * @property string $name
 * @property BrazilianState $state
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['ibge_code', 'name', 'state'])]
class City extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => BrazilianState::class,
        ];
    }

    /**
     * Scope a query to a Brazilian state.
     */
    public function scopeForState(Builder $query, BrazilianState|string $state): Builder
    {
        $stateValue = $state instanceof BrazilianState
            ? $state->value
            : Str::upper(trim($state));

        return $query->where('state', $stateValue);
    }

    /**
     * Get the municipal holidays associated with the city.
     */
    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }
}
