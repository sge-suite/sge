<?php

namespace App\Models;

use App\Concerns\CityValidationRules;
use App\Enums\BrazilianState;
use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
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
    use CityValidationRules;

    /** @use HasFactory<CityFactory> */
    use HasFactory;

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
     *
     * @param  Builder<City>  $query
     * @return Builder<City>
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
     *
     * @return HasMany<Holiday, $this>
     */
    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    /** @return HasMany<Address, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $city): void {
            Validator::make($city->getAttributes(), $city->cityRules())->validate();
        });
    }
}
